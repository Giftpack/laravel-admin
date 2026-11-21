<?php

/** Check syntax and compile-time diagnostics under the invoking PHP runtime. */
$root = dirname(__DIR__);
$files = [];
foreach (['src', 'tests', 'database', 'config', 'scripts'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);
$failures = [];
foreach ($files as $file) {
    $process = proc_open([PHP_BINARY, '-d', 'error_reporting='.E_ALL, '-d', 'display_errors=1', '-l', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start PHP syntax check.');
    }
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $diagnostics = preg_replace('/^No syntax errors detected in .*\R?/m', '', $output);
    if ($status !== 0 || trim($diagnostics) !== '') {
        $failures[] = ['path' => substr($file, strlen($root) + 1), 'status' => $status, 'diagnostics' => trim($diagnostics)];
    }
}
echo json_encode(['php' => PHP_VERSION, 'error_reporting' => E_ALL, 'files_checked' => count($files), 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failures ? 1 : 0);
