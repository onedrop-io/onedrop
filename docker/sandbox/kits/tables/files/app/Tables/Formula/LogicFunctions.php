<?php

namespace App\Tables\Formula;

use ArithmeticError;
use ValueError;

/**
 * IF, SWITCH, AND, OR and ISERROR get unevaluated nodes so branches that aren't taken never run.
 *
 * @internal
 */
final class LogicFunctions
{
    public static function ifThen(Scope $scope, Node $condition, Node $then, ?Node $else = null): mixed
    {
        if (Value::isTruthy($condition->evaluate($scope))) {
            return $then->evaluate($scope);
        }

        return $else?->evaluate($scope);
    }

    public static function switchCase(Scope $scope, Node $expression, Node ...$cases): mixed
    {
        $value = $expression->evaluate($scope);
        $count = count($cases);

        for ($i = 0; $i + 1 < $count; $i += 2) {
            if (Value::equals($value, $cases[$i]->evaluate($scope))) {
                return $cases[$i + 1]->evaluate($scope);
            }
        }

        return $count % 2 === 1 ? $cases[$count - 1]->evaluate($scope) : null;
    }

    public static function allOf(Scope $scope, Node ...$conditions): bool
    {
        foreach ($conditions as $condition) {
            if (! self::listIsTruthy($condition->evaluate($scope), all: true)) {
                return false;
            }
        }

        return true;
    }

    public static function anyOf(Scope $scope, Node ...$conditions): bool
    {
        foreach ($conditions as $condition) {
            if (self::listIsTruthy($condition->evaluate($scope), all: false)) {
                return true;
            }
        }

        return false;
    }

    public static function oddOf(mixed ...$values): bool
    {
        $true = 0;

        foreach (Value::flattenAll($values) as $value) {
            if (Value::isTruthy($value)) {
                $true++;
            }
        }

        return $true % 2 === 1;
    }

    public static function not(mixed $value): bool
    {
        return ! Value::isTruthy($value);
    }

    public static function blank(): mixed
    {
        return null;
    }

    public static function error(mixed $message = null): never
    {
        $text = Value::toText($message);

        throw new FormulaError($text === '' ? 'Error' : $text);
    }

    public static function isError(Scope $scope, Node $expression): bool
    {
        try {
            $expression->evaluate($scope);
        } catch (FormulaError|ArithmeticError|ValueError) {
            return true;
        }

        return false;
    }

    public static function true(): bool
    {
        return true;
    }

    public static function false(): bool
    {
        return false;
    }

    /**
     * A list (e.g. a lookup) is true when all (or any) of its items are; an empty list is false.
     */
    private static function listIsTruthy(mixed $value, bool $all): bool
    {
        if (! is_array($value) || $value === []) {
            return Value::isTruthy($value);
        }

        foreach (Value::flatten($value) as $item) {
            if (Value::isTruthy($item) !== $all) {
                return ! $all;
            }
        }

        return $all;
    }
}
