<?php

namespace App\Tables\Formula;

use Closure;

/**
 * The record a formula is being evaluated for.
 *
 * @internal
 */
final class Scope
{
    public function __construct(public readonly Closure $value) {}

    public function value(string $key): mixed
    {
        return ($this->value)($key);
    }
}
