<?php

declare(strict_types=1);

require_once __DIR__ . '/EntitlementGate.php';

class ProLoader
{
    private static array $capabilities = [];
    private static bool $booted = false;

    private function __construct()
    {
    }

    /**
     * Load the installed paid package — but only if it is entitled to run here.
     *
     * The check sits inside this method rather than at any call site because
     * bitCONTROLbasic/module.php reaches it from 10 places and the splitter
     * from several more, each in its own Symcon process. Guarding the call
     * sites would mean guarding all of them, forever, correctly.
     */
    public static function boot(string $dataPath): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        if (!EntitlementGate::allows($dataPath, self::currentLicensee())) {
            return;
        }

        self::loadFile($dataPath . '/pro/manifest.php');
    }

    /**
     * The Symcon licence this installation runs under.
     *
     * Read here rather than passed in: an injectable licensee would re-create
     * the overridable-identity seam that spec §2 removed (finding B).
     */
    private static function currentLicensee(): string
    {
        return function_exists('IPS_GetLicensee') ? IPS_GetLicensee() : '';
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
        $code = str_replace('__DIR__', "'" . dirname($file) . "'", $code);
        eval($code);
    }
}
