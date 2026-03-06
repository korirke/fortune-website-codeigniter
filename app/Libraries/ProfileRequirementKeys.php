<?php

namespace App\Libraries;

/**
 * ProfileRequirementKeys
 *
 * Central registry of all valid profile requirement keys,
 * plus helpers for education levels and section ordering.
 *
 * Education levels are now also stored in `education_qualification_levels`
 * for dynamic management; the static lists here serve as canonical fallbacks
 * and are used for validation when the DB is unavailable.
 */
class ProfileRequirementKeys
{
    // =========================================================
    // All valid requirement keys
    // =========================================================
    public static function all(): array
    {
        return [
            // Basic profile fields
            'BASIC_PHONE',
            'BASIC_LOCATION',
            'BASIC_TITLE',
            'BASIC_BIO',
            // Core sections
            'RESUME',
            'SKILLS',
            'EXPERIENCE',
            'EDUCATION',
            // Compliance / extras
            'PERSONAL_INFO',
            'CLEARANCES',
            'MEMBERSHIPS',
            'PUBLICATIONS',
            'COURSES',
            'REFEREES',
            // Document uploads
            'DOCUMENT_NATIONAL_ID',
            'DOCUMENT_ACADEMIC_CERT',
            'DOCUMENT_PROFESSIONAL_CERT',
            'DOCUMENT_DRIVING_LICENSE',
        ];
    }

    // =========================================================
    // Education level helpers
    // =========================================================

    /**
     * All valid education level keys.
     *
     * These match the `key` column in `education_qualification_levels`.
     * POST_GRADUATE_DIPLOMA is the canonical key (stored in educations.degreeLevel
     * for new records after the varchar migration).
     * POST_GRAD_DIPLOMA is the LEGACY enum value; kept here so old data validates.
     */
    public static function educationLevels(): array
    {
        return [
            'KCPE',
            'KCSE',
            'A_LEVEL',
            'CERTIFICATE',
            'POST_GRADUATE_DIPLOMA',
            'DIPLOMA',
            'BACHELORS',
            'MASTERS',
            'PHD',
            'OTHER',
        ];
    }

    /**
     * Human-readable labels for each education level (static fallback).
     * The authoritative labels are now in `education_qualification_levels.label`.
     */
    public static function educationLevelLabels(): array
    {
        return [
            'KCPE' => 'KCPE (Kenya Certificate of Primary Education)',
            'KCSE' => 'KCSE (Kenya Certificate of Secondary Education)',
            'A_LEVEL' => 'A-Level / Form 6',
            'CERTIFICATE' => 'Certificate',
            'POST_GRADUATE_DIPLOMA' => 'Postgraduate Diploma',
            'DIPLOMA' => 'Diploma',
            'BACHELORS' => "Bachelor's Degree",
            'MASTERS' => "Master's Degree",
            'PHD' => 'Doctor of Philosophy (PhD)',
            'OTHER' => 'Other',
        ];
    }

    /**
     * Map each education level key to the legacy `degreeLevel` enum value.
     *
     * NULL means the level is matched by keyword in the `degree` text column
     * (used for KCPE / KCSE / A_LEVEL on legacy records that predate the varchar migration).
     *
     * POST_GRADUATE_DIPLOMA → POST_GRAD_DIPLOMA covers rows inserted BEFORE the
     * varchar migration while the enum only had POST_GRAD_DIPLOMA.
     * After the migration new rows store POST_GRADUATE_DIPLOMA directly.
     */
    public static function educationLevelToDbMap(): array
    {
        return [
            'KCPE' => null,              // keyword match fallback
            'KCSE' => null,              // keyword match fallback
            'A_LEVEL' => null,              // keyword match fallback
            'CERTIFICATE' => 'CERTIFICATE',
            'POST_GRADUATE_DIPLOMA' => 'POST_GRAD_DIPLOMA', // legacy enum alias
            'DIPLOMA' => 'DIPLOMA',
            'BACHELORS' => 'BACHELORS',
            'MASTERS' => 'MASTERS',
            'PHD' => 'PHD',
            'OTHER' => 'OTHER',
        ];
    }

    /**
     * Validate a list of education level keys, accepting both the canonical keys
     * defined here AND any additional keys stored in the DB table (admin-added levels).
     *
     * Falls back to static list only when the DB is unavailable.
     */
    public static function validateEducationLevels(array $levels): array
    {
        // Start with static canonical set
        $valid = array_flip(self::educationLevels());

        // Merge DB keys (non-fatal if DB unavailable)
        try {
            $db = \Config\Database::connect();
            $rows = $db->table('education_qualification_levels')->select('key')->get()->getResultArray();
            foreach ($rows as $row) {
                $valid[$row['key']] = true;
            }
        } catch (\Throwable $e) {
            log_message('warning', 'ProfileRequirementKeys::validateEducationLevels – DB unavailable, using static list: ' . $e->getMessage());
        }

        return array_values(array_filter($levels, fn($l) => isset($valid[$l])));
    }

    // =========================================================
    // Section keys used in apply-form ordering
    // =========================================================

    /**
     * All orderable section keys for the candidate apply form.
     * 'resume' and 'application_details' are always pinned (first / last)
     * and are NOT included here — only the re-orderable middle sections.
     */
    public static function sectionKeys(): array
    {
        return [
            'basic',
            'questionnaire',
            'skills',
            'experience_general',
            'experience_specific',
            'education',
            'personal_info',
            'publications',
            'memberships',
            'clearances',
            'courses',
            'referees',
            'documents',
        ];
    }

    /**
     * Default section order (used when no admin config is set).
     */
    public static function defaultSectionOrder(): array
    {
        return self::sectionKeys();
    }
}
