<?php

$files = [
    'app/Controllers/AdminCandidate/AdminCandidates.php',
    'app/Controllers/AuditLog/AuditLog.php',
    'app/Controllers/Candidate/Candidate.php',
    'app/Controllers/Companies/Companies.php',
    'app/Controllers/Contact/Contact.php',
    'app/Controllers/Faq/Faq.php',
    'app/Controllers/Jobs/Jobs.php',
    'app/Controllers/PricingRequest/PricingRequest.php',
    'app/Controllers/RecruitmentAdmin/RecruitmentAdmin.php',
    'app/Controllers/Upload/Upload.php',
    'app/Controllers/Public/About.php',
    'app/Controllers/Public/Companies.php',
    'app/Controllers/Public/Faq.php',
    'app/Controllers/Public/Hero.php',
    'app/Controllers/Public/Jobs.php',
    'app/Controllers/Public/Search.php',
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($file);
    $original = $content;
    
    $content = str_replace('use CodeIgniter\\API\\ResponseTrait;', 'use App\\Traits\\NormalizedResponseTrait;', $content);
    $content = preg_replace('/^\s+use ResponseTrait;$/m', '    use NormalizedResponseTrait;', $content);
    
    if ($content !== $original) {
        file_put_contents($file, $content);
        echo "Updated: $file\n";
    } else {
        echo "No changes: $file\n";
    }
}

echo "Done!\n";
