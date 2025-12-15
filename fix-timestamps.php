<?php

$files = [
    'app/Models/HeroContent.php',
    'app/Models/JobSkill.php',
    'app/Models/Subscription.php',
    'app/Models/SubscriptionPlan.php',
    'app/Models/EmployerProfile.php',
    'app/Models/CandidateLanguage.php',
    'app/Models/CandidateSkill.php',
    'app/Models/Certification.php',
    'app/Models/Education.php',
    'app/Models/Experience.php',
    'app/Models/Language.php',
    'app/Models/ResumeVersion.php',
    'app/Models/Skill.php',
    'app/Models/Application.php',
    'app/Models/CandidateDomain.php',
    'app/Models/CandidateProfile.php',
    'app/Models/Company.php',
    'app/Models/Domain.php',
    'app/Models/Job.php',
];

$updated = 0;
foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($path);
    $original = $content;
    
    // Replace with regex to handle any whitespace
    $content = preg_replace('/protected\s+\$useTimestamps\s*=\s*true\s*;/', 'protected $useTimestamps = false;', $content);
    
    if ($content !== $original) {
        file_put_contents($path, $content);
        $updated++;
        echo "Updated: $file\n";
    } else {
        echo "No change needed: $file\n";
    }
}

echo "\n✅ Updated $updated files\n";
