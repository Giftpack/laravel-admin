<?php

namespace Encore\Admin\Console;

/** Validate and compile before GeneratorCommand writes any source. */
trait ValidatesGeneratedClass
{
    public function handle()
    {
        try {
            foreach ([$this->argument('name'), $this->option('namespace')] as $name) {
                if ($name !== null && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:[\\\\\/][a-zA-Z_][a-zA-Z0-9_]*)*$/', ltrim(trim($name), '\\'))) {
                    throw new \InvalidArgumentException('The name and namespace must be valid PHP class names.');
                }
            }
            if ($this instanceof ActionCommand && (($this->option('grid-row') && $this->option('grid-batch')) || ($this->option('form') && $this->option('dialog')))) {
                throw new \InvalidArgumentException('Choose one action type and one interactor.');
            }
            GeneratedSource::assertCompiles($this->buildClass($this->qualifyClass($this->getNameInput())));
            return parent::handle() === false ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
