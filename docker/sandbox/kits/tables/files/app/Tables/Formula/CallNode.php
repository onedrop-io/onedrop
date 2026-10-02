<?php

namespace App\Tables\Formula;

use Closure;

/**
 * A function call. Lazy functions (IF, AND…) get the scope and their argument
 * nodes; the rest get their evaluated arguments.
 *
 * @internal
 */
final class CallNode implements Node
{
    /**
     * @param  list<Node>  $arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly Closure $handler,
        public readonly bool $lazy,
        public readonly array $arguments,
    ) {}

    public function evaluate(Scope $scope): mixed
    {
        if ($this->lazy) {
            return ($this->handler)($scope, ...$this->arguments);
        }

        $values = [];

        foreach ($this->arguments as $argument) {
            $values[] = $argument->evaluate($scope);
        }

        return ($this->handler)(...$values);
    }
}
