<?php

namespace App\Tables\Formula;

/**
 * Recursive-descent parser. Precedence, low to high: comparisons, &, + -, * /, unary -.
 *
 * @internal
 */
final class Parser
{
    public const MaxDepth = 100;

    private const Comparisons = ['=', '!=', '<', '>', '<=', '>='];

    private int $index = 0;

    private int $depth = 0;

    /** @var array<string, true> */
    private array $references = [];

    /**
     * @param  list<Token>  $tokens
     */
    public function __construct(
        private readonly string $source,
        private readonly array $tokens,
    ) {}

    public function parse(): Node
    {
        $node = $this->comparison();

        if ($this->peek()->type !== Token::End) {
            $this->unexpected($this->peek());
        }

        return $node;
    }

    /**
     * @return list<string>
     */
    public function references(): array
    {
        return array_map('strval', array_keys($this->references));
    }

    private function comparison(): Node
    {
        $node = $this->concatenation();

        while ($this->peekOperator(self::Comparisons)) {
            $operator = (string) $this->next()->value;
            $node = new BinaryNode($operator, $node, $this->concatenation());
        }

        return $node;
    }

    private function concatenation(): Node
    {
        $node = $this->additive();

        while ($this->peekOperator(['&'])) {
            $this->next();
            $node = new BinaryNode('&', $node, $this->additive());
        }

        return $node;
    }

    private function additive(): Node
    {
        $node = $this->multiplicative();

        while ($this->peekOperator(['+', '-'])) {
            $operator = (string) $this->next()->value;
            $node = new BinaryNode($operator, $node, $this->multiplicative());
        }

        return $node;
    }

    private function multiplicative(): Node
    {
        $node = $this->unary();

        while ($this->peekOperator(['*', '/'])) {
            $operator = (string) $this->next()->value;
            $node = new BinaryNode($operator, $node, $this->unary());
        }

        return $node;
    }

    private function unary(): Node
    {
        if ($this->peekOperator(['-', '+'])) {
            $operator = $this->next()->value;
            $this->enter();
            $operand = $this->unary();
            $this->depth--;

            if ($operator === '+') {
                return $operand;
            }

            if ($operand instanceof LiteralNode && (is_int($operand->value) || is_float($operand->value))) {
                return new LiteralNode(-$operand->value);
            }

            return new NegateNode($operand);
        }

        return $this->primary();
    }

    private function primary(): Node
    {
        $token = $this->next();

        switch ($token->type) {
            case Token::Number:
            case Token::Text:
                return new LiteralNode($token->value);

            case Token::Field:
                $this->references[(string) $token->value] = true;

                return new FieldNode((string) $token->value);

            case Token::OpenParen:
                $this->enter();
                $node = $this->comparison();
                $this->expect(Token::CloseParen);
                $this->depth--;

                return $node;

            case Token::Name:
                if ($this->peek()->type === Token::OpenParen) {
                    return $this->call($token);
                }

                $upper = strtoupper((string) $token->value);

                if ($upper === 'TRUE' || $upper === 'FALSE') {
                    return new LiteralNode($upper === 'TRUE');
                }

                throw new FormulaError('Unknown name "'.$token->value.'" at character '.$this->position($token).'. Put field names in braces, like {'.$token->value.'}');
        }

        $this->unexpected($token);
    }

    private function call(Token $name): Node
    {
        $functionName = strtoupper((string) $name->value);
        $definition = Functions::find($functionName);

        if ($definition === null) {
            throw new FormulaError('Unknown function '.$functionName);
        }

        $this->next();
        $this->enter();
        $arguments = [];

        if ($this->peek()->type !== Token::CloseParen) {
            $arguments[] = $this->comparison();

            while ($this->peek()->type === Token::Comma) {
                $this->next();
                $arguments[] = $this->comparison();
            }
        }

        $this->expect(Token::CloseParen);
        $this->depth--;

        Functions::checkArgumentCount($functionName, count($arguments));

        return new CallNode($functionName, Functions::handler($functionName), $definition['lazy'], $arguments);
    }

    private function enter(): void
    {
        if (++$this->depth > self::MaxDepth) {
            throw new FormulaError('Formula is nested too deeply (max '.self::MaxDepth.' levels)');
        }
    }

    private function expect(string $type): void
    {
        $token = $this->next();

        if ($token->type !== $type) {
            $this->unexpected($token);
        }
    }

    private function unexpected(Token $token): never
    {
        if ($token->type === Token::End) {
            throw new FormulaError('Unexpected end of formula');
        }

        throw new FormulaError('Unexpected "'.$token->raw.'" at character '.$this->position($token));
    }

    private function position(Token $token): int
    {
        return Lexer::position($this->source, $token->offset);
    }

    /**
     * @param  list<string>  $operators
     */
    private function peekOperator(array $operators): bool
    {
        $token = $this->peek();

        return $token->type === Token::Operator && in_array($token->value, $operators, true);
    }

    private function peek(): Token
    {
        return $this->tokens[$this->index];
    }

    private function next(): Token
    {
        $token = $this->tokens[$this->index];

        if ($token->type !== Token::End) {
            $this->index++;
        }

        return $token;
    }
}
