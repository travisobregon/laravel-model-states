<?php

namespace Spatie\ModelStates;

use ReflectionClass;
use JsonSerializable;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Spatie\ModelStates\Events\StateChanged;
use Spatie\ModelStates\Exceptions\InvalidConfig;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;

abstract class State implements JsonSerializable
{
    /**
     * Static cache for generated state maps.
     *
     * @var array
     *
     * @see State::resolveStateMapping
     */
    protected static $generatedMapping = [];

    /** @var \Illuminate\Database\Eloquent\Model */
    protected $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Create a state object based on a value (classname or name),
     * and optionally provide its constructor arguments.
     *
     * @param string $name
     * @param mixed ...$args
     *
     * @return \Spatie\ModelStates\State
     */
    public static function make(string $name, Model $model): State
    {
        $stateClass = static::resolveStateClass($name);

        if (! is_subclass_of($stateClass, static::class)) {
            throw InvalidConfig::doesNotExtendBaseClass($name, static::class);
        }

        return new $stateClass($model);
    }

    /**
     * Create a state object based on a value (classname or name),
     * and optionally provide its constructor arguments.
     *
     * @param string $name
     * @param mixed ...$args
     *
     * @return \Spatie\ModelStates\State
     */
    public static function find(string $name, Model $model): State
    {
        return static::make($name, $model);
    }

    /**
     * Get all registered state classes.
     *
     * @return \Illuminate\Support\Collection|string[]|static[] A list of class names.
     */
    public static function all(): Collection
    {
        return collect(self::resolveStateMapping());
    }

    /**
     * The value that will be saved in the database.
     *
     * @return string
     */
    public static function getMorphClass(): string
    {
        return static::resolveStateName(static::class);
    }

    /**
     * The value that will be saved in the database.
     *
     * @return string
     */
    public function getValue(): string
    {
        return static::getMorphClass();
    }

    /**
     * Resolve the state class based on a value, for example a stored value in the database.
     *
     * @param string|\Spatie\ModelStates\State $state
     *
     * @return string
     */
    public static function resolveStateClass($state): ?string
    {
        if ($state === null) {
            return null;
        }

        if ($state instanceof State) {
            return get_class($state);
        }

        foreach (static::resolveStateMapping() as $stateClass) {
            if (! class_exists($stateClass)) {
                continue;
            }

            // Loose comparison is needed here in order to support non-string values,
            // Laravel casts their database value automatically to strings if we didn't specify the fields in `$casts`.
            if (($stateClass::$name ?? null) == $state) {
                return $stateClass;
            }
        }

        return $state;
    }

    /**
     * Resolve the name of the state, which is the value that will be saved in the database.
     *
     * Possible names are:
     *
     *    - The classname, is no explicit name is provided
     *    - A name provided in the state class as a public static property:
     *      `public static $name = 'dummy'`
     *
     * @param $state
     *
     * @return string|null
     */
    public static function resolveStateName($state): ?string
    {
        if ($state === null) {
            return null;
        }

        if ($state instanceof State) {
            $stateClass = get_class($state);
        } else {
            $stateClass = static::resolveStateClass($state);
        }

        if (class_exists($stateClass) && isset($stateClass::$name)) {
            return $stateClass::$name;
        }

        return $stateClass;
    }

    /**
     * Determine if the current state is one of an arbitrary number of other states.
     * This can be either a classname or a name.
     *
     * @param string|array ...$stateClasses
     *
     * @return bool
     */
    public function isOneOf(...$statesNames): bool
    {
        $statesNames = collect($statesNames)->flatten()->toArray();

        foreach ($statesNames as $statesName) {
            if ($this->equals($statesName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the current state equals another.
     * This can be either a classname or a name.
     *
     * @param string|\Spatie\ModelStates\State $state
     *
     * @return bool
     */
    public function equals($state): bool
    {
        return self::resolveStateClass($state)
            === self::resolveStateClass($this);
    }

    /**
     * Determine if the current state equals another.
     * This can be either a classname or a name.
     *
     * @param string|\Spatie\ModelStates\State $state
     *
     * @return bool
     */
    public function is($state): bool
    {
        return $this->equals($state);
    }

    public function __toString(): string
    {
        return static::getMorphClass();
    }

    /**
     * @param string|\Spatie\ModelStates\Transition $transitionClass
     * @param mixed ...$args
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function transition($transition, ...$args): Model
    {
        if (is_string($transition)) {
            $transition = new $transition($this->model, ...$args);
        }

        if (method_exists($transition, 'canTransition')) {
            if (! $transition->canTransition()) {
                throw CouldNotPerformTransition::notAllowed($this->model, $transition);
            }
        }

        $mutatedModel = app()->call([$transition, 'handle']);

        event(new StateChanged($this, $mutatedModel->state, $transition, $this->model));

        return $mutatedModel;
    }

    /**
     * @param string|\Spatie\ModelStates\State $state
     * @param mixed ...$args
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function transitionTo($state, ...$args): Model
    {
        if (! method_exists($this->model, 'resolveTransitionClass')) {
            throw InvalidConfig::resolveTransitionNotFound($this->model);
        }

        $transition = $this->model->resolveTransitionClass(
            static::resolveStateClass($this),
            static::resolveStateClass($state)
        );

        return $this->transition($transition, ...$args);
    }

    /**
     * Get the transitionable states from this state.
     *
     * @return array
     */
    public function transitionableStates($field = null): array
    {
        return $this->model->transitionableStates(get_class($this), $field);
    }

    /**
     * This method is used to find all available implementations of a given abstract state class.
     * Finding all implementations can be done in two ways:.
     *
     *    - The developer can define his own mapping directly in abstract state classes
     *      via the `protected $states = []` property
     *    - If no specific mapping was provided, the same directory where the abstract state class lives
     *      is scanned, and all concrete state classes extending the abstract state class will be provided.
     *
     * @return array
     */
    private static function resolveStateMapping(): array
    {
        if (isset(static::$states)) {
            return static::$states;
        }

        if (isset(self::$generatedMapping[static::class])) {
            return self::$generatedMapping[static::class];
        }

        $reflection = new ReflectionClass(static::class);

        ['dirname' => $directory] = pathinfo($reflection->getFileName());

        $files = scandir($directory);

        unset($files[0], $files[1]);

        $namespace = $reflection->getNamespaceName();

        $resolvedStates = [];

        foreach ($files as $file) {
            ['filename' => $className] = pathinfo($file);

            $stateClass = $namespace.'\\'.$className;

            if (! is_subclass_of($stateClass, static::class)) {
                continue;
            }

            $resolvedStates[] = $stateClass;
        }

        self::$generatedMapping[static::class] = $resolvedStates;

        return self::$generatedMapping[static::class];
    }

    public function jsonSerialize()
    {
        return $this->getValue();
    }
}
