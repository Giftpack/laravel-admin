<?php

namespace Encore\Admin\Console;

use Symfony\Component\Process\Process;

/** Compile source without executing it or creating a destination file. */
final class GeneratedSource
{
    public static function assertCompiles(string $source): void
    {
        // TOKEN_PARSE alone misses compiler-only errors, including reserved type names.
        $process = new Process([PHP_BINARY, '-l']);
        $process->setInput($source);
        $process->setTimeout(10);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \InvalidArgumentException('Generated PHP does not compile: '.trim($process->getErrorOutput().$process->getOutput()));
        }
    }
}
