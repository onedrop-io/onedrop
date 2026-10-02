<?php

namespace App\Tables\Formula;

/**
 * Splits formula source into tokens.
 *
 * @internal
 */
final class Lexer
{
    private const TwoCharacterOperators = ['!=', '<>', '<=', '>='];

    private const OneCharacterOperators = ['=', '<', '>', '&', '+', '-', '*', '/'];

    public function __construct(private readonly string $source) {}

    /**
     * @return list<Token>
     */
    public function tokenize(): array
    {
        $source = $this->source;
        $length = strlen($source);
        $tokens = [];
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];

            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                $i++;

                continue;
            }

            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($source[$i + 1]))) {
                preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $source, $match, 0, $i);
                $raw = $match[0];
                $number = preg_match('/^\d+$/', $raw) === 1 && strlen(ltrim($raw, '0')) < 19 ? (int) $raw : (float) $raw;
                $tokens[] = new Token(Token::Number, $number, $i, $raw);
                $i += strlen($raw);

                continue;
            }

            if ($char === '"' || $char === "'") {
                [$text, $end] = $this->readText($i, $char);
                $tokens[] = new Token(Token::Text, $text, $i, substr($source, $i, $end - $i));
                $i = $end;

                continue;
            }

            if ($char === '{') {
                $close = strpos($source, '}', $i + 1);

                if ($close === false) {
                    throw new FormulaError('Missing "}" for the field at character '.self::position($source, $i));
                }

                $key = substr($source, $i + 1, $close - $i - 1);

                if (trim($key) === '') {
                    throw new FormulaError('Empty field reference at character '.self::position($source, $i));
                }

                $tokens[] = new Token(Token::Field, $key, $i, substr($source, $i, $close - $i + 1));
                $i = $close + 1;

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $match, 0, $i);
                $tokens[] = new Token(Token::Name, $match[0], $i, $match[0]);
                $i += strlen($match[0]);

                continue;
            }

            $pair = substr($source, $i, 2);

            if (in_array($pair, self::TwoCharacterOperators, true)) {
                $tokens[] = new Token(Token::Operator, $pair === '<>' ? '!=' : $pair, $i, $pair);
                $i += 2;

                continue;
            }

            if (in_array($char, self::OneCharacterOperators, true)) {
                $tokens[] = new Token(Token::Operator, $char, $i, $char);
                $i++;

                continue;
            }

            if ($char === '(' || $char === ')' || $char === ',') {
                $tokens[] = new Token($char, $char, $i, $char);
                $i++;

                continue;
            }

            $character = mb_substr(substr($source, $i, 4), 0, 1);

            throw new FormulaError('Unexpected "'.$character.'" at character '.self::position($source, $i));
        }

        $tokens[] = new Token(Token::End, null, $length, '');

        return $tokens;
    }

    /**
     * 1-based character (not byte) position of a byte offset, for error messages.
     */
    public static function position(string $source, int $offset): int
    {
        return mb_strlen(substr($source, 0, $offset)) + 1;
    }

    /**
     * @return array{0: string, 1: int} The text and the byte offset just past the closing quote.
     */
    private function readText(int $start, string $quote): array
    {
        $source = $this->source;
        $length = strlen($source);
        $text = '';
        $i = $start + 1;

        while ($i < $length) {
            $char = $source[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $next = $source[$i + 1];
                $text .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    default => $next,
                };
                $i += 2;

                continue;
            }

            if ($char === $quote) {
                return [$text, $i + 1];
            }

            $text .= $char;
            $i++;
        }

        throw new FormulaError('Missing closing quote for the text at character '.self::position($source, $start));
    }
}
