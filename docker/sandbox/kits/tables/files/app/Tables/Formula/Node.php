<?php

namespace App\Tables\Formula;

/**
 * A node of a compiled formula's syntax tree.
 *
 * @internal
 */
interface Node
{
    public function evaluate(Scope $scope): mixed;
}
