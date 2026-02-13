<?php

require_once 'vendor/autoload.php';

$config = new \Config\Database();
$db = \Config\Database::connect();

$profileModel = new \App\Models\CandidateProfile();

$profiles = $db->table('candidate_profiles')
    ->select('id, userId')
    ->get()
    ->getResultArray();

$total = count($profiles);
$updated = 0;
$failed = 0;

echo "<h2>Recalculating {$total} profiles...</h2>";
echo "<pre>";

foreach ($profiles as $index => $profile) {
    $num = $index + 1;
    
    try {
        $result = $profileModel->recalculateExperience($profile['id']);
        
        if ($result) {
            $updated++;
            echo "[{$num}/{$total}] ✅ Updated: {$profile['id']}\n";
        } else {
            $failed++;
            echo "[{$num}/{$total}] ⚠️  Failed: {$profile['id']}\n";
        }
    } catch (Exception $e) {
        $failed++;
        echo "[{$num}/{$total}] ❌ Error: {$profile['id']} - {$e->getMessage()}\n";
    }

    flush();
    ob_flush();
}

echo "\n========================================\n";
echo "✅ Complete! Updated: {$updated} | Failed: {$failed}\n";
echo "========================================\n";
echo "</pre>";
