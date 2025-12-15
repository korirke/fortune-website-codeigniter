<?php
/**
 * Script to fix all Swagger annotations across all controllers
 */

$controllersPath = __DIR__ . '/app/Controllers';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($controllersPath),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    
    $content = file_get_contents($file->getPathname());
    $original = $content;
    
    // Fix response="200" to response=200 (with proper formatting)
    $content = preg_replace_callback(
        '/@OA\\\\Response\(response="(\d+)"(?:,\s*description="([^"]+)")?\)/',
        function($matches) {
            $code = $matches[1];
            $desc = isset($matches[2]) ? $matches[2] : 'Success';
            return "@OA\Response(\n     *         response={$code},\n     *         description=\"{$desc}\"\n     *     )";
        },
        $content
    );
    
    // Fix response="201" to response=201
    $content = preg_replace_callback(
        '/@OA\\\\Response\(response="(\d+)"(?:,\s*description="([^"]+)")?\)/',
        function($matches) {
            $code = $matches[1];
            $desc = isset($matches[2]) ? $matches[2] : 'Created';
            return "@OA\Response(\n     *         response={$code},\n     *         description=\"{$desc}\"\n     *     )";
        },
        $content
    );
    
    if ($content !== $original) {
        file_put_contents($file->getPathname(), $content);
        echo "Fixed: " . $file->getPathname() . "\n";
    }
}

echo "Done!\n";
