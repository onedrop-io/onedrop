<?php

namespace App\Tables\Formula;

/**
 * Unary minus.
 *
 * @internal
 */
final class NegateNode implements Node
{
    public function __construct(public readonly Node $operand) {}

    public function evaluate(Scope $scope): mixed
    {
        return -Value::toNumber($this->operand->evaluate($scope));
    }
}
