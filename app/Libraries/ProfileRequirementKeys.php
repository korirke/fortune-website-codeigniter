<?php

namespace App\Libraries;

final class ProfileRequirementKeys
{
    // Basic
    public const BASIC_PHONE = 'BASIC_PHONE';
    public const BASIC_LOCATION = 'BASIC_LOCATION';
    public const BASIC_BIO = 'BASIC_BIO';
    public const BASIC_TITLE = 'BASIC_TITLE';

    // Core profile blocks
    public const RESUME = 'RESUME';
    public const SKILLS = 'SKILLS';
    public const EXPERIENCE = 'EXPERIENCE';
    public const EDUCATION = 'EDUCATION';

    // Compliance blocks
    public const PERSONAL_INFO = 'PERSONAL_INFO';
    public const CLEARANCES = 'CLEARANCES';
    public const MEMBERSHIPS = 'MEMBERSHIPS';
    public const PUBLICATIONS = 'PUBLICATIONS';
    public const COURSES = 'COURSES';
    public const REFEREES = 'REFEREES';

    // Documents
    public const DOCUMENT_NATIONAL_ID = 'DOCUMENT_NATIONAL_ID';
    public const DOCUMENT_ACADEMIC_CERT = 'DOCUMENT_ACADEMIC_CERT';
    public const DOCUMENT_PROFESSIONAL_CERT = 'DOCUMENT_PROFESSIONAL_CERT';

    public static function all(): array
    {
        return [
            self::BASIC_PHONE,
            self::BASIC_LOCATION,
            self::BASIC_BIO,
            self::BASIC_TITLE,
            self::RESUME,
            self::SKILLS,
            self::EXPERIENCE,
            self::EDUCATION,
            self::PERSONAL_INFO,
            self::CLEARANCES,
            self::MEMBERSHIPS,
            self::PUBLICATIONS,
            self::COURSES,
            self::REFEREES,
            self::DOCUMENT_NATIONAL_ID,
            self::DOCUMENT_ACADEMIC_CERT,
            self::DOCUMENT_PROFESSIONAL_CERT,
        ];
    }
}
