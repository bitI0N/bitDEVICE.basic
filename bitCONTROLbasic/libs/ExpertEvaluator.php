<?php

declare(strict_types=1);

class ExpertEvaluator
{
    private const MAX_SCRIPT_LENGTH = 65536;

    private const ALLOWED_FUNCTIONS = [
        // Math
        'abs', 'ceil', 'floor', 'round', 'max', 'min', 'fmod', 'intdiv',
        'pow', 'sqrt', 'log', 'log10', 'pi', 'sin', 'cos', 'tan',
        'asin', 'acos', 'atan', 'atan2', 'deg2rad', 'rad2deg',
        'is_nan', 'is_infinite', 'is_finite',
        // Array
        'array_keys', 'array_values', 'array_merge', 'array_filter',
        'array_map', 'array_slice', 'array_pop', 'array_push',
        'array_shift', 'array_unshift', 'array_reverse', 'array_unique',
        'array_sum', 'array_product', 'array_count_values',
        'array_key_exists', 'array_search', 'array_column',
        'array_combine', 'array_diff', 'array_intersect',
        'in_array', 'count', 'sort', 'rsort', 'asort', 'arsort',
        'ksort', 'krsort', 'usort', 'uasort', 'uksort',
        'array_fill', 'array_pad', 'array_chunk', 'array_splice',
        'range', 'compact', 'list', 'implode', 'explode',
        // String
        'strlen', 'strpos', 'strrpos', 'substr', 'str_contains',
        'str_starts_with', 'str_ends_with', 'str_replace',
        'str_pad', 'str_repeat', 'str_word_count',
        'strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'ucwords',
        'trim', 'ltrim', 'rtrim', 'nl2br', 'wordwrap',
        'number_format', 'sprintf', 'printf', 'sscanf',
        'preg_match', 'preg_match_all', 'preg_replace', 'preg_split',
        'mb_strlen', 'mb_substr', 'mb_strtolower', 'mb_strtoupper',
        'chunk_split', 'str_getcsv',
        // Type
        'intval', 'floatval', 'strval', 'boolval',
        'is_int', 'is_float', 'is_string', 'is_bool', 'is_array',
        'is_null', 'is_numeric', 'isset', 'empty', 'unset',
        'gettype', 'settype',
        // Date/Time
        'time', 'date', 'mktime', 'strtotime', 'microtime',
        'gmdate', 'strftime', 'idate',
        'date_create', 'date_format', 'date_diff', 'date_add', 'date_sub',
        'date_interval_create_from_date_string',
        // JSON
        'json_encode', 'json_decode', 'json_last_error', 'json_last_error_msg',
        // IP-Symcon API (safe read/write operations)
        'GetValue', 'GetValueBoolean', 'GetValueInteger', 'GetValueFloat', 'GetValueString',
        'SetValue', 'SetValueBoolean', 'SetValueInteger', 'SetValueFloat', 'SetValueString',
        'RequestAction',
        'IPS_GetVariable', 'IPS_GetVariableProfile', 'IPS_VariableExists',
        'IPS_GetObject', 'IPS_GetObjectIDByIdent', 'IPS_GetObjectIDByName',
        'IPS_ObjectExists', 'IPS_GetName', 'IPS_GetParent',
        'IPS_GetChildrenIDs', 'IPS_HasChildren',
        'IPS_GetProperty', 'IPS_GetConfiguration',
        'IPS_GetInstance', 'IPS_GetInstanceListByModuleID',
        'IPS_GetEvent', 'IPS_GetEventList',
        'IPS_GetMedia', 'IPS_GetMediaContent',
        'IPS_GetLink', 'IPS_GetLinkList',
        'IPS_GetCategory', 'IPS_GetCategoryList',
        'IPS_GetKernelDir', 'IPS_GetKernelDate', 'IPS_GetKernelVersion',
        'IPS_LogMessage', 'IPS_SendDebug',
        // Control flow
        'array_walk', 'array_walk_recursive',
        // Misc safe
        'var_export', 'print_r', 'base64_encode', 'base64_decode',
        'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode',
        'http_build_query', 'parse_str', 'parse_url',
        'crc32', 'md5', 'sha1', 'hash',
    ];

    private const BLOCKED_PATTERNS = [
        '/\b(exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i',
        '/\b(file_put_contents|file_get_contents|fopen|fwrite|fread|fclose)\s*\(/i',
        '/\b(unlink|rmdir|mkdir|rename|copy|move_uploaded_file|tempnam|tmpfile)\s*\(/i',
        '/\b(glob|scandir|opendir|readdir|closedir|is_dir|is_file)\s*\(/i',
        '/\b(curl_init|curl_exec|curl_setopt|curl_close|curl_multi_exec)\s*\(/i',
        '/\b(fsockopen|stream_socket_client|stream_socket_server|socket_create)\s*\(/i',
        '/\b(mail|header|setcookie|session_start|session_destroy)\s*\(/i',
        '/\b(eval|assert|create_function|call_user_func|call_user_func_array)\s*\(/i',
        '/\b(include|include_once|require|require_once)\s*[\s(]/i',
        '/\b(ini_set|ini_get|set_time_limit|set_error_handler|set_exception_handler)\s*\(/i',
        '/\b(putenv|getenv|php_uname|phpinfo|phpversion|get_defined_vars)\s*\(/i',
        '/\b(register_shutdown_function|register_tick_function)\s*\(/i',
        '/\b(class_alias|define|constant)\s*\(/i',
        '/\b(extract|compact)\s*\(/i',
        '/`[^`]*`/',
        '/\$\$/',
        '/\$_[A-Z]/',
    ];

    public static function validate(string $script, array $outputAliases): string
    {
        $trimmed = trim($script);

        if ($trimmed === '') {
            return 'Script must not be empty';
        }

        if (strlen($script) > self::MAX_SCRIPT_LENGTH) {
            return 'Script exceeds maximum length of ' . self::MAX_SCRIPT_LENGTH . ' bytes';
        }

        $cleaned = self::stripTags($trimmed);

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $cleaned)) {
                preg_match($pattern, $cleaned, $m);
                $matched = trim($m[1] ?? $m[0], '(');
                return 'Blocked operation: ' . $matched;
            }
        }

        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $cleaned, $matches)) {
            foreach ($matches[1] as $funcName) {
                if (!in_array($funcName, self::ALLOWED_FUNCTIONS, true)
                    && !in_array(strtolower($funcName), ['fn', 'array'], true)) {
                    return 'Function not allowed: ' . $funcName . ' — only whitelisted functions are permitted';
                }
            }
        }

        $syntaxError = self::checkSyntax($cleaned);
        if ($syntaxError !== '') {
            return $syntaxError;
        }

        return '';
    }

    public static function execute(string $script, array $triggerAliasValues, array $outputAliases): array
    {
        $code = self::stripTags(trim($script));

        $preamble = '';
        foreach ($triggerAliasValues as $alias => $value) {
            $varName = ltrim($alias, '$');
            $preamble .= '$' . $varName . ' = ' . var_export($value, true) . ";\n";
        }

        $outputInit = '';
        foreach ($outputAliases as $alias) {
            $varName = ltrim($alias, '$');
            $outputInit .= '$' . $varName . " = null;\n";
        }

        $outputCollection = '$__outputs = [];' . "\n";
        foreach ($outputAliases as $alias) {
            $varName = ltrim($alias, '$');
            $outputCollection .= '$__outputs[\'' . $alias . '\'] = $' . $varName . ";\n";
        }
        $outputCollection .= 'return $__outputs;' . "\n";

        $fullScript = $preamble . $outputInit . $code . "\n" . $outputCollection;

        try {
            $results = eval($fullScript);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Script execution failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($results)) {
            throw new \RuntimeException('Script execution failed: unexpected return value');
        }

        return array_filter($results, static fn($value) => $value !== null);
    }

    private static function stripTags(string $script): string
    {
        $script = preg_replace('/^<\?php\s*/', '', $script);
        $script = preg_replace('/\s*\?>$/', '', $script);

        return $script;
    }

    private static function checkSyntax(string $code): string
    {
        try {
            eval('if(false){' . $code . '}');
        } catch (\ParseError $e) {
            return 'Syntax error: ' . $e->getMessage();
        }

        return '';
    }
}
