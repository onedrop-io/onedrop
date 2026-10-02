<?php

namespace App\Tables\Formula;

/**
 * @internal
 */
final class Token
{
    public const Number = 'number';

    public const Text = 'text';

    public const Field = 'field';

    public const Name = 'name';

    public const Operator = 'operator';

    public const OpenParen = '(';

    public const CloseParen = ')';

    public const Comma = ',';

    public const End = 'end';

    /**
     * @param  int  $offset  Byte offset of the token in the source.
     */
    public function __construct(
        public readonly string $type,
        public readonly int|float|string|null $value,
        public readonly int $offset,
        public readonly string $raw,
    ) {}
}
