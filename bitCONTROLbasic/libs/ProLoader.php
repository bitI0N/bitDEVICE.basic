<?php

declare(strict_types=1);

class ProLoader
{
    private static array $capabilities = [];
    private static bool $booted = false;

    private function __construct()
    {
    }

    public static function boot(string $dataPath): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $proDir = $dataPath . '/pro';
        $manifest = $proDir . '/manifest.php';
        if (!file_exists($manifest)) {
            return;
        }

        $files = glob($proDir . '/*.php');
        if (count($files) > 1) {
            foreach ($files as $file) {
                if (basename($file) === 'manifest.php') {
                    continue;
                }
                self::loadFile($file);
            }
        } else {
            require_once $manifest;
        }
    }

    public static function register(string $key, object $provider): void
    {
        self::$capabilities[$key] = $provider;
    }

    public static function get(string $key): ?object
    {
        return self::$capabilities[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::$capabilities[$key]);
    }

    public static function tier(): string
    {
        if (self::has('expert')) {
            return 'pro';
        }

        if (self::has('formula')) {
            return 'plus';
        }

        return 'community';
    }

    public static function reset(): void
    {
        self::$capabilities = [];
        self::$booted = false;
    }

    private static function loadFile(string $file): void
    {
        $code = file_get_contents($file);
        if ($code === false) {
            return;
        }
        $code = preg_replace('/^<\?php\s*/i', '', $code);
        eval($code);
    }
}
