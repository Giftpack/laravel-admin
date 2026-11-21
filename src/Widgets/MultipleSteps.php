<?php

namespace Encore\Admin\Widgets;

use Illuminate\Contracts\Support\Renderable;

class MultipleSteps implements Renderable
{
    /**
     * @var int|string
     */
    protected $current;

    /**
     * @var array
     */
    protected $steps = [];

    /**
     * @var string
     */
    protected $stepName = 'step';

    protected $workflow;

    /**
     * MultipleSteps constructor.
     *
     * @param array $steps
     * @param null  $current
     * @param string|null $workflow Stable server identifier for independent copies of one definition.
     */
    public function __construct($steps = [], $current = null, ?string $workflow = null)
    {
        $this->steps = $steps;
        $this->workflow = hash('sha256', json_encode([$steps, $workflow], JSON_THROW_ON_ERROR));

        $this->current = $this->resolveCurrentStep($steps, $current);
    }

    /**
     * @param array $steps
     * @param null  $current
     * @param string|null $workflow Stable server identifier for independent copies of one definition.
     *
     * @return static
     */
    public static function make($steps, $current = null, ?string $workflow = null): self
    {
        return new static($steps, $current, $workflow);
    }

    /**
     * @param array      $steps
     * @param string|int $current
     *
     * @return string|int
     */
    protected function resolveCurrentStep($steps, $current)
    {
        $current = $current ?? request($this->stepName, 0);

        if ((!is_int($current) && !is_string($current)) || !isset($steps[$current])) {
            $current = key($steps);
        }

        return $current;
    }

    /**
     * @return string|null
     */
    public function render()
    {
        if (!$this->steps) {
            return '';
        }
        $class = $this->steps[$this->current];

        if (!is_subclass_of($class, StepForm::class)) {
            admin_error("Class [{$class}] must be a sub-class of [Encore\Admin\Widgets\StepForm].");

            return;
        }

        /** @var StepForm $step */
        $step = new $class();

        return $step
            ->setWorkflow($this->workflow)
            ->setSteps(array_keys($this->steps))
            ->setCurrent($this->current)
            ->render();
    }
}
