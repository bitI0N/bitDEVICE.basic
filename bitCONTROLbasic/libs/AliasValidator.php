<?php

declare(strict_types=1);

class AliasValidator
{
    private const PATTERN = '/^\$[a-zA-Z_][a-zA-Z0-9_]*$/';
    private const MAX_LENGTH = 32;
    private const RESERVED = [
        '$this',
        '$_GET',
        '$_POST',
        '$_SERVER',
        '$_SESSION',
        '$_REQUEST',
        '$_ENV',
        '$_FILES',
        '$_COOKIE',
        '$GLOBALS',
        '$input',
        '$inputs',
        '$output',
        '$outputs',
    ];

    public static function validate(string $alias, array $existingAliases = []): string
    {
        if ($alias === '') {
            return 'Alias must not be empty';
        }

        if (!str_starts_with($alias, '$')) {
            return 'Alias must start with $';
        }

        if (str_contains($alias, ' ')) {
            return 'Alias must not contain spaces';
        }

        if (strlen($alias) > self::MAX_LENGTH) {
            return 'Alias must not exceed ' . self::MAX_LENGTH . ' characters';
        }

        if (!preg_match(self::PATTERN, $alias)) {
            return 'Alias must match pattern: letter or underscore after $, then alphanumeric or underscore';
        }

        if (in_array($alias, self::RESERVED, true)) {
            return 'Alias is a reserved name: ' . $alias;
        }

        if (in_array($alias, $existingAliases, true)) {
            return 'Alias already exists: ' . $alias;
        }

        return '';
    }

    public static function validateAll(array $triggers, array $outputs): array
    {
        $errors = [];
        $seen = [];

        foreach ($triggers as $index => $trigger) {
            $alias = $trigger['alias'] ?? '';
            if ($alias === '') {
                continue;
            }

            $error = self::validate($alias, $seen);
            if ($error !== '') {
                $position = $index + 1;
                $errors[] = "Trigger #{$position} ({$alias}): {$error}";
            }

            $seen[] = $alias;
        }

        foreach ($outputs as $index => $output) {
            $alias = $output['alias'] ?? '';
            if ($alias === '') {
                continue;
            }

            $error = self::validate($alias, $seen);
            if ($error !== '') {
                $position = $index + 1;
                $errors[] = "Output #{$position} ({$alias}): {$error}";
            }

            $seen[] = $alias;
        }

        return $errors;
    }
}
