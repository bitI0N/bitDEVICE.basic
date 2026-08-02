<?php

declare(strict_types=1);

require_once __DIR__ . '/TokenVerifier.php';
require_once __DIR__ . '/PackageDigest.php';

/**
 * The single decision point for whether paid code may load.
 *
 * It lives here, at the load boundary, rather than in the splitter, because
 * bitCONTROLbasic/module.php reaches ProLoader::boot() from 10 call sites
 * (lines 78, 236, 272, 303, 323, 838, 846, 859, 1006, 1022) that never touch
 * the splitter, and because Symcon runs each module instance in its own
 * process — ProLoader::$capabilities is static and does not cross that
 * boundary, so the splitter's tier check protected the splitter's process
 * only. Together those made data/pro/ a portable folder: copy it anywhere and
 * paid features ran, with no license.json present at all (finding A).
 *
 * The gate is a pure function of disk plus the Symcon licensee, so it holds
 * regardless of which entry path runs first and regardless of process.
 *
 * It never deletes anything. Refusal means "load nothing"; removing data/pro/
 * happens only on explicit revocation, user deactivation, or a detected tier
 * mismatch — never on expiry, which the perpetual model forbids.
 */
final class EntitlementGate
{
    /** Mirrors LicenseManager::GRACE_PERIOD_DAYS. */
    private const GRACE_PERIOD_DAYS = 14;

    private function __construct()
    {
    }

    public static function allows(string $dataPath, string $licensee): bool
    {
        $proDir = $dataPath . '/pro';

        // 0. Nothing installed, nothing to authorise.
        if (!is_file($proDir . '/manifest.php')) {
            return false;
        }

        // An unlicensed Symcon instance reports an empty licensee. Treating
        // that as a subject would make one token minted with an empty `sub`
        // unlock every such installation at once.
        if ($licensee === '') {
            return false;
        }

        // 1. A token exists, verifies against the pinned key, and its subject
        //    is this Symcon licensee.
        $payload = self::verifiedPayload($dataPath, $licensee);
        if ($payload === null) {
            return false;
        }

        // 2. Not past expiry plus grace. Deliberately a superset of
        //    active+grace so this path and the splitter's cannot disagree
        //    about an expired licence.
        if (!self::withinTerm($payload)) {
            return false;
        }

        // 3. The tier the package declares is covered by the token. Read from
        //    an adjacent marker — never by executing the manifest to find out
        //    whether the manifest may be executed.
        $declared = self::declaredTier($proDir);
        if ($declared === '') {
            return false;
        }

        if (self::rank($declared) > self::rank((string) ($payload['tier'] ?? 'community'))) {
            return false;
        }

        // 4. The package on disk is the one this token was issued for.
        $digest = PackageDigest::compute($proDir);
        $claimed = (string) ($payload['package_digest'] ?? '');
        if ($digest === '' || $claimed === '') {
            return false;
        }

        return hash_equals($claimed, $digest);
    }

    /** @return array<string, mixed>|null */
    private static function verifiedPayload(string $dataPath, string $licensee): ?array
    {
        $file = $dataPath . '/license.json';
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $state = json_decode($raw, true);
        if (!is_array($state) || empty($state['token']) || !is_string($state['token'])) {
            return null;
        }

        return TokenVerifier::verify($state['token'], $licensee);
    }

    /** @param array<string, mixed> $payload */
    private static function withinTerm(array $payload): bool
    {
        // No (int) cast: a ~100-year term produces exp ~4.94e9, which exceeds
        // the signed 32-bit maximum. On a 32-bit build PHP promotes the
        // overflow to float, where the comparison stays exact at this
        // magnitude — the cast is what breaks, not the arithmetic.
        $exp = $payload['exp'] ?? null;
        if (!is_numeric($exp) || $exp <= 0) {
            return false;
        }

        return time() <= $exp + (self::GRACE_PERIOD_DAYS * 86400);
    }

    private static function declaredTier(string $proDir): string
    {
        $marker = $proDir . '/TIER';
        if (!is_file($marker)) {
            return '';
        }

        $tier = @file_get_contents($marker);
        if ($tier === false) {
            return '';
        }

        $tier = trim($tier);

        return in_array($tier, ['plus', 'pro'], true) ? $tier : '';
    }

    private static function rank(string $tier): int
    {
        return match ($tier) {
            'pro' => 2,
            'plus' => 1,
            default => 0,
        };
    }
}
