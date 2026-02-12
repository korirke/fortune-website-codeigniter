<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class RecalculateExperiences extends BaseCommand
{
    protected $group       = 'maintenance';
    protected $name        = 'recalculate:experiences';
    protected $description = 'Recalculate all candidate experiences (overlap-safe)';

    public function run(array $params)
    {
        $profileModel = new \App\Models\CandidateProfile();
        
        $db = \Config\Database::connect();
        $profiles = $db->table('candidate_profiles')
            ->select('id, userId')
            ->get()
            ->getResultArray();

        $total = count($profiles);
        $updated = 0;
        $failed = 0;

        CLI::write("Found {$total} candidate profiles to recalculate...", 'yellow');
        CLI::newLine();

        foreach ($profiles as $index => $profile) {
            $num = $index + 1;
            
            try {
                $result = $profileModel->recalculateExperience($profile['id']);
                
                if ($result) {
                    $updated++;
                    CLI::write("[{$num}/{$total}] ✅ Updated profile: {$profile['id']}", 'green');
                } else {
                    $failed++;
                    CLI::write("[{$num}/{$total}] ⚠️  Failed profile: {$profile['id']}", 'yellow');
                }
            } catch (\Exception $e) {
                $failed++;
                CLI::write("[{$num}/{$total}] ❌ Error profile: {$profile['id']} - " . $e->getMessage(), 'red');
            }

            // Progress update every 50 records
            if ($num % 50 === 0) {
                CLI::newLine();
                CLI::write("Progress: {$num}/{$total} ({$updated} updated, {$failed} failed)", 'cyan');
                CLI::newLine();
            }
        }

        CLI::newLine();
        CLI::write("========================================", 'cyan');
        CLI::write("✅ Recalculation Complete!", 'green');
        CLI::write("Total: {$total} | Updated: {$updated} | Failed: {$failed}", 'yellow');
        CLI::write("========================================", 'cyan');
    }
}