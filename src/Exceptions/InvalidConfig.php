<?php

namespace Spatie\ModelStates\Exceptions;

use Exception;
use Spatie\ModelStates\State;
use Spatie\ModelStates\HasStates;
use Spatie\ModelStates\Transition;
use Illuminate\Database\Eloquent\Model;

class InvalidConfig extends Exception
{
    public static function unknownState(string $field, Model $model): InvalidConfig
    {
        $modelClass = get_class($model);

        return UnknownState::make($field, $modelClass);
    }

    public static function fieldNotFound(string $stateClass, Model $model): InvalidConfig
    {
        $modelClass = get_class($model);

        return new self("No state field was found for the state {$stateClass} in {$modelClass}, did you forget to provide a mapping in {$modelClass}::registerStates()?");
    }

    public static function fieldDoesNotExtendState(string $field, string $expectedStateClass, string $actualClass): InvalidConfig
    {
        return FieldDoesNotExtendState::make($field, $expectedStateClass, $actualClass);
    }

    public static function doesNotExtendState(string $class): InvalidConfig
    {
        return self::doesNotExtendBaseClass($class, State::class);
    }

    public static function doesNotExtendTransition(string $class): InvalidConfig
    {
        return self::doesNotExtendBaseClass($class, Transition::class);
    }

    public static function doesNotExtendBaseClass(string $class, string $baseClass): InvalidConfig
    {
        return ClassDoesNotExtendBaseClass::make($class, $baseClass);
    }

    public static function resolveTransitionNotFound(Model $model): InvalidConfig
    {
        $modelClass = get_class($model);

        $trait = HasStates::class;

        return MissingTraitOnModel::make($modelClass, $trait);
    }
}
