<?php

declare(strict_types=1);

class FormulaEvaluator
{
    private const ALLOWED_FUNCTIONS = [
        'min', 'max', 'abs', 'round', 'floor', 'ceil',
        'clamp', 'avg', 'sum',
    ];

    private const FORBIDDEN_KEYWORDS = [
        'if', 'else', 'elseif', 'while', 'for', 'foreach',
        'switch', 'match', 'return', 'function', 'class', 'new',
        'throw', 'try', 'catch', 'finally', 'eval', 'echo', 'print',
    ];

    public static function validate(string $formula, array $knownAliases): string
    {
        if (trim($formula) === '') {
            return 'Formula must not be empty';
        }

        if (substr_count($formula, '(') !== substr_count($formula, ')')) {
            return 'Unbalanced parentheses';
        }

        if (preg_match('/(?<![!<>])=(?!=)/', $formula)) {
            return 'Assignment operator is not allowed';
        }

        if (str_contains($formula, ';')) {
            return 'Semicolons are not allowed (single expression only)';
        }

        if (str_contains($formula, '`')) {
            return 'Backticks are not allowed';
        }

        if (str_contains($formula, '"') || str_contains($formula, "'")) {
            return 'String literals are not allowed';
        }

        if (preg_match('/\$\$/', $formula)) {
            return 'Variable variables are not allowed';
        }

        if (preg_match('/\$_[A-Z]/', $formula)) {
            return 'Superglobals are not allowed';
        }

        if (str_contains($formula, '[') || str_contains($formula, ']')) {
            return 'Array access is not allowed';
        }

        if (str_contains($formula, '->') || str_contains($formula, '::')) {
            return 'Object access is not allowed';
        }

        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $formula)) {
                return 'Forbidden keyword: ' . $keyword;
            }
        }

        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $formula, $matches)) {
            foreach ($matches[1] as $funcName) {
                if (!in_array(strtolower($funcName), self::ALLOWED_FUNCTIONS, true)) {
                    return 'Function not allowed: ' . $funcName;
                }
            }
        }

        if (preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $formula, $matches)) {
            foreach ($matches[0] as $variable) {
                if (!in_array($variable, $knownAliases, true)) {
                    return 'Unknown variable: ' . $variable;
                }
            }
        }

        try {
            $dummyValues = [];
            foreach ($knownAliases as $alias) {
                $dummyValues[$alias] = 1.0;
            }
            self::evaluate($formula, $dummyValues);
        } catch (\Throwable $e) {
            return 'Expression is not evaluable: ' . $e->getMessage();
        }

        return '';
    }

    public static function evaluate(string $formula, array $aliasValues): float|int
    {
        $tokens = self::tokenize($formula);
        $pos = 0;
        $result = self::parseExpression($tokens, $pos, $aliasValues);

        if ($pos < count($tokens)) {
            throw new \RuntimeException('Unexpected token: ' . $tokens[$pos]['value']);
        }

        return $result;
    }

    private static function tokenize(string $formula): array
    {
        $tokens = [];
        $len = strlen($formula);
        $i = 0;

        while ($i < $len) {
            if (ctype_space($formula[$i])) {
                $i++;
                continue;
            }

            if ($formula[$i] === '$') {
                $start = $i;
                $i++;
                while ($i < $len && (ctype_alnum($formula[$i]) || $formula[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['type' => 'variable', 'value' => substr($formula, $start, $i - $start)];
                continue;
            }

            if (ctype_digit($formula[$i]) || $formula[$i] === '.') {
                $start = $i;
                $hasDot = $formula[$i] === '.';
                $i++;
                while ($i < $len && (ctype_digit($formula[$i]) || (!$hasDot && $formula[$i] === '.'))) {
                    if ($formula[$i] === '.') {
                        $hasDot = true;
                    }
                    $i++;
                }
                $tokens[] = ['type' => 'number', 'value' => substr($formula, $start, $i - $start)];
                continue;
            }

            if (ctype_alpha($formula[$i]) || $formula[$i] === '_') {
                $start = $i;
                while ($i < $len && (ctype_alnum($formula[$i]) || $formula[$i] === '_')) {
                    $i++;
                }
                $tokens[] = ['type' => 'function', 'value' => substr($formula, $start, $i - $start)];
                continue;
            }

            if (in_array($formula[$i], ['+', '-', '*', '/', '%', '(', ')', ','], true)) {
                $tokens[] = ['type' => 'operator', 'value' => $formula[$i]];
                $i++;
                continue;
            }

            throw new \RuntimeException('Unexpected character: ' . $formula[$i]);
        }

        return $tokens;
    }

    private static function parseExpression(array $tokens, int &$pos, array $aliasValues): float|int
    {
        $result = self::parseTerm($tokens, $pos, $aliasValues);

        while ($pos < count($tokens) && in_array($tokens[$pos]['value'] ?? '', ['+', '-'], true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = self::parseTerm($tokens, $pos, $aliasValues);
            $result = $op === '+' ? $result + $right : $result - $right;
        }

        return $result;
    }

    private static function parseTerm(array $tokens, int &$pos, array $aliasValues): float|int
    {
        $result = self::parseUnary($tokens, $pos, $aliasValues);

        while ($pos < count($tokens) && in_array($tokens[$pos]['value'] ?? '', ['*', '/', '%'], true)) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $right = self::parseUnary($tokens, $pos, $aliasValues);
            $result = match ($op) {
                '*' => $result * $right,
                '/' => $right != 0 ? $result / $right : throw new \RuntimeException('Division by zero'),
                '%' => $right != 0 ? $result % $right : throw new \RuntimeException('Modulo by zero'),
            };
        }

        return $result;
    }

    private static function parseUnary(array $tokens, int &$pos, array $aliasValues): float|int
    {
        if ($pos < count($tokens) && $tokens[$pos]['value'] === '-') {
            $pos++;
            return -self::parsePrimary($tokens, $pos, $aliasValues);
        }

        if ($pos < count($tokens) && $tokens[$pos]['value'] === '+') {
            $pos++;
        }

        return self::parsePrimary($tokens, $pos, $aliasValues);
    }

    private static function parsePrimary(array $tokens, int &$pos, array $aliasValues): float|int
    {
        if ($pos >= count($tokens)) {
            throw new \RuntimeException('Unexpected end of expression');
        }

        $token = $tokens[$pos];

        if ($token['type'] === 'number') {
            $pos++;
            return str_contains($token['value'], '.') ? (float) $token['value'] : (int) $token['value'];
        }

        if ($token['type'] === 'variable') {
            $pos++;
            if (!array_key_exists($token['value'], $aliasValues)) {
                throw new \RuntimeException('Unknown variable: ' . $token['value']);
            }
            return (float) $aliasValues[$token['value']];
        }

        if ($token['type'] === 'function') {
            $funcName = strtolower($token['value']);
            $pos++;

            if ($pos >= count($tokens) || $tokens[$pos]['value'] !== '(') {
                throw new \RuntimeException('Expected ( after function ' . $token['value']);
            }
            $pos++;

            $args = [];
            if ($pos < count($tokens) && $tokens[$pos]['value'] !== ')') {
                $args[] = self::parseExpression($tokens, $pos, $aliasValues);
                while ($pos < count($tokens) && $tokens[$pos]['value'] === ',') {
                    $pos++;
                    $args[] = self::parseExpression($tokens, $pos, $aliasValues);
                }
            }

            if ($pos >= count($tokens) || $tokens[$pos]['value'] !== ')') {
                throw new \RuntimeException('Expected ) after function arguments');
            }
            $pos++;

            return self::callFunction($funcName, $args);
        }

        if ($token['value'] === '(') {
            $pos++;
            $result = self::parseExpression($tokens, $pos, $aliasValues);
            if ($pos >= count($tokens) || $tokens[$pos]['value'] !== ')') {
                throw new \RuntimeException('Expected closing parenthesis');
            }
            $pos++;
            return $result;
        }

        throw new \RuntimeException('Unexpected token: ' . $token['value']);
    }

    private static function callFunction(string $name, array $args): float|int
    {
        return match ($name) {
            'min' => count($args) >= 2 ? min(...$args) : throw new \RuntimeException('min() requires at least 2 arguments'),
            'max' => count($args) >= 2 ? max(...$args) : throw new \RuntimeException('max() requires at least 2 arguments'),
            'abs' => count($args) === 1 ? abs($args[0]) : throw new \RuntimeException('abs() requires exactly 1 argument'),
            'round' => match (count($args)) {
                1 => round($args[0]),
                2 => round($args[0], (int) $args[1]),
                default => throw new \RuntimeException('round() requires 1 or 2 arguments'),
            },
            'floor' => count($args) === 1 ? (int) floor($args[0]) : throw new \RuntimeException('floor() requires exactly 1 argument'),
            'ceil' => count($args) === 1 ? (int) ceil($args[0]) : throw new \RuntimeException('ceil() requires exactly 1 argument'),
            'clamp' => count($args) === 3 ? ($args[1] < $args[0] ? $args[0] : ($args[1] > $args[2] ? $args[2] : $args[1])) : throw new \RuntimeException('clamp() requires exactly 3 arguments (min, value, max)'),
            'avg' => count($args) >= 1 ? array_sum($args) / count($args) : throw new \RuntimeException('avg() requires at least 1 argument'),
            'sum' => count($args) >= 1 ? array_sum($args) : throw new \RuntimeException('sum() requires at least 1 argument'),
            default => throw new \RuntimeException('Unknown function: ' . $name),
        };
    }
}
