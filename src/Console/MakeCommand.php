<?php

namespace Encore\Admin\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class MakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'admin:make {name}
        {--model=}
        {--title=}
        {--stub= : Path to the custom stub file. }
        {--namespace=}
        {--O|output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make admin controller';

    /**
     * @var ResourceGenerator
     */
    protected $generator;

    /**
     * @var string
     */
    protected $controllerName;

    /**
     * @var string
     */
    protected $modelName;

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->modelName = $this->getModelName();
        if (!$this->modelExists()) {
            $this->error('The model must be an existing, instantiable Eloquent model class.');
            return self::FAILURE;
        }

        try {
            $this->controllerName = $this->getControllerName();
            foreach ([$this->controllerName, $this->option('namespace')] as $name) {
                if ($name !== null && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:[\\\\\/][a-zA-Z_][a-zA-Z0-9_]*)*$/', ltrim($name, '\\'))) {
                    throw new \InvalidArgumentException('The controller name and namespace must be valid PHP class names.');
                }
            }
            if (($stub = $this->option('stub')) && (!is_file($stub) || !is_readable($stub))) {
                throw new \InvalidArgumentException('The stub file does not exist or is not readable.');
            }
            $this->generator = $this->modelName ? new ResourceGenerator($this->modelName) : null;
            if ($this->option('output')) {
                if ($this->generator === null) {
                    throw new \InvalidArgumentException('The --output option requires a model.');
                }
                $this->output($this->modelName);
                return self::SUCCESS;
            }
            // Validate the complete source before GeneratorCommand creates directories or writes a file.
            $this->assertCompiles($this->buildClass($this->qualifyClass($this->getNameInput())));
            if (parent::handle() === false) {
                return self::FAILURE;
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if ($this->modelName) {
            $path = Str::plural(Str::kebab(class_basename($this->modelName)));
            $this->line('');
            $this->comment('Add the following route to app/Admin/routes.php:');
            $this->info("    \$router->resource('{$path}', {$this->controllerName}::class);");
        }
        return self::SUCCESS;
    }

    /**
     * @return array|string|null
     */
    protected function getControllerName()
    {
        return $this->argument('name');
    }

    /**
     * @return array|string|null
     */
    protected function getModelName()
    {
        return $this->option('model');
    }

    /**
     * @throws \ReflectionException
     *
     * @return array|bool|string|null
     */
    protected function getTitle()
    {
        if ($title = $this->option('title')) {
            return $title;
        }

        return __((new \ReflectionClass($this->modelName))->getShortName());
    }

    /**
     * @param string $modelName
     */
    protected function output($modelName)
    {
        $this->alert("laravel-admin controller code for model [{$modelName}]");

        $this->info($this->generator->generateGrid());
        $this->info($this->generator->generateShow());
        $this->info($this->generator->generateForm());
    }

    /**
     * Determine if the model is exists.
     *
     * @return bool
     */
    protected function modelExists()
    {
        if (empty($this->modelName)) {
            return true;
        }

        return is_string($this->modelName) && class_exists($this->modelName)
            && is_subclass_of($this->modelName, Model::class)
            && (new \ReflectionClass($this->modelName))->isInstantiable();
    }

    /**
     * Replace the class name for the given stub.
     *
     * @param string $stub
     * @param string $name
     *
     * @return string
     */
    protected function replaceClass($stub, $name)
    {
        $stub = parent::replaceClass($stub, $name);
        if ($this->generator === null) {
            return $stub;
        }

        return strtr($stub, [
            'DummyTitleLiteral' => var_export($this->getTitle(), true),
            'DummyModelNamespace' => ltrim($this->modelName, '\\'),
            'DummyTitle' => addcslashes($this->getTitle(), "\\'"),
            'DummyModel' => class_basename($this->modelName),
            'DummyGrid' => $this->indentCodes($this->generator->generateGrid()),
            'DummyShow' => $this->indentCodes($this->generator->generateShow()),
            'DummyForm' => $this->indentCodes($this->generator->generateForm()),
        ]);
    }

    /**
     * @param string $code
     *
     * @return string
     */
    protected function indentCodes($code)
    {
        $indent = str_repeat(' ', 8);

        return rtrim($indent.preg_replace("/\r\n/", "\r\n{$indent}", $code));
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        if ($stub = $this->option('stub')) {
            return $stub;
        }

        if ($this->modelName) {
            return __DIR__.'/stubs/controller.stub';
        }

        return __DIR__.'/stubs/blank.stub';
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     *
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        $namespace = trim(str_replace('/', '\\', (string) ($this->option('namespace') ?: config('admin.route.namespace'))), '\\');
        $rootNamespace = trim($rootNamespace, '\\');
        if ($namespace !== $rootNamespace && !Str::startsWith($namespace, $rootNamespace.'\\')) {
            throw new \InvalidArgumentException("The generator namespace must be inside the application root namespace [{$rootNamespace}].");
        }

        return $namespace;
    }

    /** Compile without executing source or writing a destination, including compiler-only name conflicts. */
    protected function assertCompiles($source)
    {
        $process = new Process([PHP_BINARY, '-l']);
        $process->setInput($source);
        $process->setTimeout(10);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \InvalidArgumentException('Generated controller does not compile: '.trim($process->getErrorOutput().$process->getOutput()));
        }
    }

    /**
     * Get the desired class name from the input.
     *
     * @return string
     */
    protected function getNameInput()
    {
        $this->type = $this->qualifyClass($this->controllerName);

        return $this->controllerName;
    }
}
