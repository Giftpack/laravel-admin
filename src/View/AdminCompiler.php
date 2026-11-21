<?php

namespace Encore\Admin\View;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\CompilerInterface;

/** Select package escaping without changing the host's Blade compiler. */
class AdminCompiler implements CompilerInterface
{
    public function __construct(protected BladeCompiler $compiler)
    {
    }

    protected function compilerFor($path): BladeCompiler
    {
        $real = realpath($path);
        foreach (app('view')->getFinder()->getHints()['admin'] ?? [] as $directory) {
            $directory = realpath($directory);
            if ($real && $directory && str_starts_with($real, $directory.DIRECTORY_SEPARATOR)) {
                // Clone at compile time so later host directives/settings stay available.
                $compiler = clone $this->compiler;
                $compiler->withoutDoubleEncoding();

                return $compiler;
            }
        }

        return $this->compiler;
    }

    public function getCompiledPath($path)
    {
        return $this->compilerFor($path)->getCompiledPath($path);
    }

    public function isExpired($path)
    {
        return $this->compilerFor($path)->isExpired($path);
    }

    public function compile($path)
    {
        return $this->compilerFor($path)->compile($path);
    }

    public function __call($method, $arguments)
    {
        return $this->compiler->$method(...$arguments);
    }
}
