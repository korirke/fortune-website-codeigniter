<?php

/**
 * Description: Controller for the "Structured Longlist" feature.
 *
 * POST /api/applications/structured-longlist/generate
 *   Accepts { jobId, questionIds[] } and returns a professionally formatted
 *   XLSX file as a browser download.
 *
 *   - Title row: centred, bold, uppercase job title
 *   - Candidate numbering column (#)
 *   - Questionnaire question columns with candidate answers auto-populated
 *   - Clean dark-lined table layout
 *   - "Notes" column always appended last
 *   - Filename includes the job title for easy identification
 *
 * POST /api/applications/structured-longlist/download
 *   Alias for /generate.
 */

namespace App\Controllers\Applications;

use App\Controllers\BaseController;
use App\Services\StructuredLonglistService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class StructuredLonglist extends BaseController
{
    // ─────────────────────────────────────────────────────────────────────
    // AUTHZ HELPERS
    // ─────────────────────────────────────────────────────────────────────

    private function getAuthUser()
    {
        return $this->request->user ?? null;
    }

    private function isAdminRole(string $role): bool
    {
        return in_array($role, ['SUPER_ADMIN', 'MODERATOR'], true);
    }

    private function getEmployerProfileByUserId(string $userId): ?array
    {
        $db = \Config\Database::connect();
        return $db->table('employer_profiles')
            ->where('userId', $userId)
            ->get()
            ->getRowArray();
    }

    private function getCompanyIdForEmployerUser($user): ?string
    {
        if (!$user) {
            return null;
        }

        if (!in_array($user->role ?? '', ['EMPLOYER', 'HR_MANAGER'], true)) {
            return null;
        }

        $profile = $this->getEmployerProfileByUserId($user->id);
        return $profile['companyId'] ?? null;
    }

    private function authorizeJobAccess(string $jobId): array
    {
        $user = $this->getAuthUser();
        if (!$user) {
            return [false, $this->fail('Unauthorized', 401)];
        }

        if ($this->isAdminRole($user->role ?? '')) {
            return [true, null];
        }

        $companyId = $this->getCompanyIdForEmployerUser($user);
        if (!$companyId) {
            return [false, $this->fail('Employer profile not found', 404)];
        }

        $job = (new \App\Models\Job())->find($jobId);
        if (!$job) {
            return [false, $this->failNotFound('Job not found')];
        }

        if (($job['companyId'] ?? null) !== $companyId) {
            return [false, $this->fail('Forbidden: not your company job', 403)];
        }

        return [true, null];
    }

    // ─────────────────────────────────────────────────────────────────────
    // ENDPOINTS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST /api/applications/structured-longlist/generate
     *
     * Body (JSON):
     * {
     *   "jobId":       "abc123",
     *   "questionIds": ["jqst_xxx", "jqst_yyy"]
     * }
     *
     * Returns: .xlsx binary download
     */
    public function generate()
    {
        try {
            // ── Auth ─────────────────────────────────────────────────────
            $user = $this->getAuthUser();
            if (!$user) {
                return $this->fail('Unauthorized', 401);
            }

            // ── Parse body ───────────────────────────────────────────────
            $body = $this->request->getJSON(true);

            $jobId = $body['jobId'] ?? null;
            if (!$jobId) {
                return $this->fail('jobId is required', 400);
            }

            // ── AUTHZ ────────────────────────────────────────────────────
            [$ok, $resp] = $this->authorizeJobAccess($jobId);
            if (!$ok) {
                return $resp;
            }

            // ── Resolve company scope for non-admin users ────────────────
            $companyId = null;
            if (!$this->isAdminRole($user->role ?? '')) {
                $companyId = $this->getCompanyIdForEmployerUser($user);
                if (!$companyId) {
                    return $this->fail('Employer profile not found', 404);
                }
            }

            // ── Question IDs config ──────────────────────────────────────
            $questionIds = $body['questionIds'] ?? [];
            if (!is_array($questionIds)) {
                $questionIds = [];
            }

            // ── Build data ───────────────────────────────────────────────
            $service = new StructuredLonglistService();
            $result = $service->build($jobId, $companyId, $questionIds);

            $headers = $result['headers'];
            $dataRows = $result['rows'];
            $title = $result['title'];
            $jobTitle = $result['jobTitle'];
            $questions = $result['questions'] ?? [];

            // Map question texts to their types for widths
            $questionTypeMap = [];
            foreach ($questions as $q) {
                $questionTypeMap[$q['questionText']] = $q['type'] ?? 'OPEN_ENDED';
            }

            // ── Build XLSX ───────────────────────────────────────────────
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Structured Longlist');

            if (empty($dataRows)) {
                $sheet->setCellValue('A1', 'No applications found for this job');
                $filename = $this->sanitizeFilename($jobTitle) . '_structured_longlist_empty_' . date('Y-m-d') . '.xlsx';
            } else {
                $totalCols = count($headers);
                $lastCol = $this->columnLetter($totalCols - 1);

                // ──────────────────────────────────────────────────────────
                // ROW 1: Title
                // ──────────────────────────────────────────────────────────
                $sheet->setCellValue('A1', $title);
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 16,
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_MEDIUM,
                        ],
                    ],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(32);

                // ──────────────────────────────────────────────────────────
                // ROW 2: Headers
                // ──────────────────────────────────────────────────────────
                for ($i = 0; $i < $totalCols; $i++) {
                    $cellRef = $this->columnLetter($i) . '2';
                    $label = $headers[$i];
                    $sheet->setCellValue($cellRef, $label);

                    $sheet->getStyle($cellRef)->applyFromArray([
                        'font' => [
                            'bold' => true,
                            'size' => 11,
                        ],
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'wrapText' => true,
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_MEDIUM,
                            ],
                        ],
                    ]);
                }
                $sheet->getRowDimension(2)->setRowHeight(42);

                // ──────────────────────────────────────────────────────────
                // ROW 3+: Data rows
                // ──────────────────────────────────────────────────────────
                $rowNum = 3;
                foreach ($dataRows as $row) {
                    for ($c = 0; $c < $totalCols; $c++) {
                        $cellRef = $this->columnLetter($c) . $rowNum;
                        $value = $row[$c] ?? '';
                        $sheet->setCellValue($cellRef, $value);

                        $style = [
                            'font' => [
                                'size' => 11,
                            ],
                            'borders' => [
                                'allBorders' => [
                                    'borderStyle' => Border::BORDER_THIN,
                                ],
                            ],
                            'alignment' => [
                                'vertical' => Alignment::VERTICAL_CENTER,
                                'wrapText' => true,
                            ],
                        ];

                        // Centre-align the # column
                        if ($c === 0) {
                            $style['alignment']['horizontal'] = Alignment::HORIZONTAL_CENTER;
                            $style['font']['bold'] = true;
                        }

                        $sheet->getStyle($cellRef)->applyFromArray($style);
                    }

                    $sheet->getRowDimension($rowNum)->setRowHeight(36);
                    $rowNum++;
                }

                // ── Column widths ────────────────────────────────────────
                $this->applyColumnWidths($sheet, $headers, $questionTypeMap);

                // ── Freeze pane below headers ────────────────────────────
                $sheet->freezePane('A3');

                // ── Auto-filter on header row ────────────────────────────
                $sheet->setAutoFilter("A2:{$lastCol}2");

                $filename = $this->sanitizeFilename($jobTitle) . '_structured_longlist_' . date('Y-m-d') . '.xlsx';
            }

            // ── Write to stream and return ───────────────────────────────
            $writer = new Xlsx($spreadsheet);
            $tmp = fopen('php://temp', 'w+b');
            $writer->save($tmp);
            rewind($tmp);
            $binary = stream_get_contents($tmp);
            fclose($tmp);

            return $this->response
                ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setHeader('Cache-Control', 'max-age=0')
                ->setBody($binary);

        } catch (\Exception $e) {
            log_message('error', 'StructuredLonglist::generate error: ' . $e->getMessage());
            log_message('error', 'Stack trace: ' . $e->getTraceAsString());

            return $this->respond([
                'success' => false,
                'message' => 'Failed to generate structured longlist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alias – frontend may call either /generate or /download.
     */
    public function download()
    {
        return $this->generate();
    }

    // ─────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Convert zero-based column index to Excel column letter(s).
     */
    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26) - 1;
        }
        return $letter;
    }

    /**
     * Set sensible default widths per known column label.
     */
    private function applyColumnWidths($sheet, array $headers, array $questionTypeMap): void
    {
        $defaultWidths = [
            '#' => 5,
            'Candidate' => 28,
            'Qualification' => 45,
            'Email' => 35,
            'Graduation Year' => 14,
            'Contacts' => 18,
            'Expected Salary' => 18,
            'Notes' => 22,
        ];

        for ($i = 0; $i < count($headers); $i++) {
            $col = $this->columnLetter($i);
            $label = $headers[$i];

            if (isset($defaultWidths[$label])) {
                $width = $defaultWidths[$label];
            } elseif (isset($questionTypeMap[$label])) {
                $width = ($questionTypeMap[$label] === 'YES_NO') ? 14 : 30;
            } else {
                $width = 22;
            }

            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * Sanitize a string for use in a filename.
     */
    private function sanitizeFilename(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $name);
        $clean = preg_replace('/\s+/', '_', trim($clean));
        return strtolower($clean) ?: 'longlist';
    }
}
