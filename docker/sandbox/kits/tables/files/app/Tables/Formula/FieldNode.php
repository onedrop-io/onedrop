<?php

namespace App\Tables\Formula;

/**
 * @internal
 */
final class FieldNode implements Node
{
    public function __construct(public readonly string $key) {}

    public function evaluate(Scope $scope): mixed
    {
        return ($scope->value)($this->key);
    }
}
