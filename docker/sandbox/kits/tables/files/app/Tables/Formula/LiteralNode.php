<?php

namespace App\Tables\Formula;

/**
 * @internal
 */
final class LiteralNode implements Node
{
    public function __construct(public readonly int|float|string|bool|null $value) {}

    public function evaluate(Scope $scope): mixed
    {
        return $this->value;
    }
}
