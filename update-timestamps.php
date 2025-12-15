<?php

/**
 * Script to update all models to set $useTimestamps = false
 */

$files = glob(__DIR__ . '/app/Models/*.php');

$updated = 0;
foreach ($files as $file) {
    $content = file_get_contents($file);
    $original = $content;
    
    // Replace protected $useTimestamps = true; with false (try multiple patterns)
    $patterns = [
        'protected $useTimestamps = true;',
        'protected $useTimestamps = true ;',
        'protected $useTimestamps= true;',
        'protected $useTimestamps =true;',
    ];
    
    foreach ($patterns as $pattern) {
        $content = str_replace($pattern, 'protected $useTimestamps = false;', $content);
    }
    
    // Also try with regex-like replacement for any whitespace variations
    $content = preg_replace('/protected\s+\$useTimestamps\s*=\s*true\s*;/', 'protected $useTimestamps = false;', $content);
    
    if ($content !== $original) {
        file_put_contents($file, $content);
        $updated++;
        echo "Updated: " . basename($file) . "\n";
    }
}

echo "\n✅ Updated $updated files\n";
