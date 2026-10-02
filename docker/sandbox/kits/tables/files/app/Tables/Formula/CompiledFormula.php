<?php

namespace App\Tables\Formula;

use ArithmeticError;
use Closure;
use DivisionByZeroError;
use ValueError;

/**
 * A parsed, checked formula. Evaluate it once per record.
 */
final class CompiledFormula
{
    /**
     * @param  list<string>  $references
     */
    public function __construct(
        private readonly Node $root,
        private readonly array $references,
    ) {}

    /**
     * @return list<string> field keys referenced
     */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * Evaluates the formula for one record.
     *
     * @param  callable(string): mixed  $value  A field's value by key. Special keys: '@id', '@createdAt', '@updatedAt'.
     * @return int|float|string|bool|\DateTimeImmutable|list<mixed>|null
     *
     * @throws FormulaError
     */
    public function evaluate(callable $value): mixed
    {
        $scope = new Scope($value instanceof Closure ? $value : Closure::fromCallable($value));

        try {
            return Value::result($this->root->evaluate($scope));
        } catch (DivisionByZeroError) {
            throw new FormulaError("Can't divide by zero");
        } catch (ArithmeticError|ValueError) {
            throw new FormulaError('Number is out of range');
        }
    }
}
