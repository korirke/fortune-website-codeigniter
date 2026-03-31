<?php

/**
 * Description: Completely standalone controller for the "Structured Longlist"
 * feature. Provides two endpoints:
 *
 *   POST /api/applications/structured-longlist/generate
 *     Accepts { jobId, experienceColumns[], extraColumns[] } and returns
 *     the XLSX file directly as a download.  experienceColumns are the
 *     dynamic columns whose values are auto-searched against candidate
 *     education, certifications, courses and work-experience records.
 *     Default experience columns match the uploaded template:
 *       ["Diploma in Insurance","General Insurance experience 2-3 yrs"]
 *   POST /api/applications/structured-longlist/download
 *     Identical to /generate – provided as a semantic alias so the
 *     frontend can call either endpoint.
 *
 * A "Notes" column is always appended as the last column.
 */

namespace App\Controllers\Applications;

use App\Controllers\BaseController;
use App\Services\StructuredLonglistService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
     *   "jobId":              "abc123",                           // REQUIRED
     *   "experienceColumns":  ["Diploma in Insurance", "…"],     // optional
     *   "extraColumns":       ["Extra Field 1"]                  // optional
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

            // ── Columns config ───────────────────────────────────────────
            $experienceColumns = $body['experienceColumns'] ?? [];
            $extraColumns      = $body['extraColumns']      ?? [];

            // Ensure arrays
            if (!is_array($experienceColumns)) {
                $experienceColumns = [];
            }
            if (!is_array($extraColumns)) {
                $extraColumns = [];
            }

            // ── Build data ───────────────────────────────────────────────
            $service = new StructuredLonglistService();
            $result  = $service->build($jobId, $companyId, $experienceColumns, $extraColumns);

            $headers  = $result['headers'];
            $dataRows = $result['rows'];
            $title    = $result['title'];

            // ── Build XLSX ───────────────────────────────────────────────
            $spreadsheet = new Spreadsheet();
            $sheet       = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Structured Longlist');

            if (empty($dataRows)) {
                $sheet->setCellValue('A1', 'No applications found for this job');
                $filename = 'structured_longlist_empty_' . date('Y-m-d_His') . '.xlsx';
            } else {
                $totalCols = count($headers);
                $lastCol   = $this->columnLetter($totalCols - 1);

                // ── ROW 1: Title (merged across all columns) ─────────────
                $sheet->setCellValue('A1', $title);
                $sheet->mergeCells("A1:{$lastCol}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
                $sheet->getStyle('A1')->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension(1)->setRowHeight(24);

                // ── ROW 2: Headers ───────────────────────────────────────
                for ($i = 0; $i < $totalCols; $i++) {
                    $cellRef = $this->columnLetter($i) . '2';
                    $sheet->setCellValue($cellRef, $headers[$i]);

                    $sheet->getStyle($cellRef)->getFont()->setBold(true)->setSize(11);
                    $sheet->getStyle($cellRef)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                        ->setVertical(Alignment::VERTICAL_CENTER)
                        ->setWrapText(true);
                    $sheet->getStyle($cellRef)->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN);
                }
                $sheet->getRowDimension(2)->setRowHeight(42);

                // ── ROW 3+: Data rows ────────────────────────────────────
                $rowNum = 3;
                foreach ($dataRows as $row) {
                    for ($c = 0; $c < $totalCols; $c++) {
                        $cellRef = $this->columnLetter($c) . $rowNum;
                        $value   = $row[$c] ?? '';
                        $sheet->setCellValue($cellRef, $value);

                        $sheet->getStyle($cellRef)->getFont()->setSize(11);
                        $sheet->getStyle($cellRef)->getBorders()->getAllBorders()
                            ->setBorderStyle(Border::BORDER_THIN);

                        if (strlen($value) > 30) {
                            $sheet->getStyle($cellRef)->getAlignment()->setWrapText(true);
                        }
                    }
                    $sheet->getRowDimension($rowNum)->setRowHeight(16.5);
                    $rowNum++;
                }

                // ── Column widths ────────────────────────────────────────
                $this->applyColumnWidths($sheet, $headers);

                // ── Freeze pane below headers ────────────────────────────
                $sheet->freezePane('A3');

                $filename = 'structured_longlist_' . date('Y-m-d_His') . '.xlsx';
            }

            // ── Write to stream and return ───────────────────────────────
            $writer = new Xlsx($spreadsheet);
            $tmp    = fopen('php://temp', 'w+b');
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
                'error'   => $e->getMessage(),
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
            $index  = intdiv($index, 26) - 1;
        }
        return $letter;
    }

    /**
     * Set sensible default widths per known column label.
     */
    private function applyColumnWidths($sheet, array $headers): void
    {
        $defaultWidths = [
            'Candidate'        => 25,
            'Qualification'    => 45,
            'Email'            => 35,
            'Graduation year'  => 14,
            'Contacts'         => 18,
            'Expected salary'  => 18,
            'Notes'            => 20,
        ];

        for ($i = 0; $i < count($headers); $i++) {
            $col   = $this->columnLetter($i);
            $label = $headers[$i];
            $width = $defaultWidths[$label] ?? 22; // dynamic/extra columns default 22
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }
}
