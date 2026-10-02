<?php

namespace App\Tables\Formula;

use DateTimeInterface;

/**
 * @internal
 */
final class BinaryNode implements Node
{
    public function __construct(
        public readonly string $operator,
        public readonly Node $left,
        public readonly Node $right,
    ) {}

    public function evaluate(Scope $scope): mixed
    {
        $left = $this->left->evaluate($scope);
        $right = $this->right->evaluate($scope);

        return match ($this->operator) {
            '+' => Value::toNumber($left) + Value::toNumber($right),
            '-' => $this->subtract($left, $right),
            '*' => Value::toNumber($left) * Value::toNumber($right),
            '/' => $this->divide($left, $right),
            '&' => Value::toText($left).Value::toText($right),
            '=' => Value::equals($left, $right),
            '!=' => ! Value::equals($left, $right),
            '<' => Value::compare($left, $right) === -1,
            '>' => Value::compare($left, $right) === 1,
            '<=' => in_array(Value::compare($left, $right), [-1, 0], true),
            '>=' => in_array(Value::compare($left, $right), [0, 1], true),
        };
    }

    private function subtract(mixed $left, mixed $right): int|float
    {
        if (Value::unwrap($left) instanceof DateTimeInterface && Value::unwrap($right) instanceof DateTimeInterface) {
            throw new FormulaError('Use DATETIME_DIFF to subtract dates');
        }

        return Value::toNumber($left) - Value::toNumber($right);
    }

    private function divide(mixed $left, mixed $right): int|float
    {
        $divisor = Value::toNumber($right);

        if ($divisor == 0) {
            throw new FormulaError("Can't divide by zero");
        }

        return Value::toNumber($left) / $divisor;
    }
}
