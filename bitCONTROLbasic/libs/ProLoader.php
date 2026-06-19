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

        $manifest = $dataPath . '/pro/manifest.php';
        if (file_exists($manifest)) {
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

    // Clears all registered capabilities and resets the booted flag for license state changes.
    public static function reset(): void
    {
        self::$capabilities = [];
        self::$booted = false;
    }
}
