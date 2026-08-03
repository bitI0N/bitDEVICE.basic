<?php

declare(strict_types=1);

/**
 * RS256 token verification against keys pinned in this file.
 *
 * The key used to be read from bitLICENSEsplitter/libs/keys/<kid>.pub, which
 * made forging a licence a matter of overwriting one file — no reverse
 * engineering needed, reproduced against the production LicenseManager
 * (finding C). Pinning removes the file, and with it the substitution vector.
 *
 * Rotation ships a module update carrying current AND predecessor key, so kid
 * resolution stays strict. There is deliberately no "newest key" fallback: a
 * token is verified against the key its kid names, or it is rejected.
 *
 * Scope of the protection, stated plainly: this class ships as plain source in
 * the public Community artifact — bitCONTROLbasic is source-visible by design
 * and yakpro-po only processes the paid tiers. Pinning therefore raises the
 * cost from "drop a .pub file next to it" to "edit and re-deploy the shipped
 * PHP". It does not claim to stop someone willing to do the latter; see the
 * Accepted Ceiling in the license-security-remediation spec.
 */
final class TokenVerifier
{
    /** @var array<string, string> kid => PEM public key */
    public const PINNED_KEYS = [
        '2025-01' => "-----BEGIN PUBLIC KEY-----\n"
            . "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtV+jfaEo0WHaNdsQ81eE\n"
            . "5JICzaj6zx/jD4lRsAINk7gFZMiVfUxq6sCMgyjd+7WQn6W58jUQQwpRq7+da29s\n"
            . "+8P7/YQ3h8TeiXW5NpLApWksX0l5aXVZwRktMMqY5dujEzPFOGcWRAH96udl2zWD\n"
            . "rbovDSxjnIFsIytS+Y1DaUv13xZliTZ61F7gQk5D65qg8LcPwcfbr/uj6NYtX5gd\n"
            . "b0c6wXV8FvAr/Mjr2aQ4hmy/02+rGeAqa5hr847Fa+sVrfA5bbsgY5Q/5DXaql8D\n"
            . "UrvwVvVA+4gVmI/ZgRDfkxzWaLN1O/ojJ1+PXZWwbsuvXmiNFqDft7PdKNp9bmf0\n"
            . "UwIDAQAB\n"
            . "-----END PUBLIC KEY-----\n",
    ];

    /** @var array<string, string>|null Extra keys, test harness only. */
    private static ?array $testKeys = null;

    private function __construct()
    {
    }

    /** @return array<string> */
    public static function knownKids(): array
    {
        return array_keys(self::keys());
    }

    /**
     * Trust an additional key for the duration of a test run.
     *
     * Refuses unless PHPUnit is loaded in this process, so a production caller
     * cannot widen the trusted set. That guard is not a security boundary
     * against code execution — anyone who can run code in the Symcon process
     * has already won — it exists so an accidental or careless production call
     * does nothing.
     */
    public static function trustForTesting(string $kid, string $pem): void
    {
        if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
            return;
        }

        self::$testKeys[$kid] = $pem;
    }

    /** @return array<string, string> */
    public static function keys(): array
    {
        return self::PINNED_KEYS + (self::$testKeys ?? []);
    }

    /**
     * Verify an RS256 JWT and return its payload, or null on any failure.
     *
     * @param string|null $expectedSub When set, a token whose `sub` differs is
     *                                 rejected — this binds a licence to the
     *                                 Symcon instance it was issued for.
     * @return array<string, mixed>|null
     */
    public static function verify(string $token, ?string $expectedSub = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $headerJson = self::base64UrlDecode($headerB64);
        if ($headerJson === false) {
            return null;
        }

        $header = json_decode($headerJson, true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256') {
            return null;
        }

        $kid  = (string) ($header['kid'] ?? '');
        $keys = self::keys();
        if (!isset($keys[$kid])) {
            return null;
        }

        $signatureRaw = self::base64UrlDecode($signatureB64);
        if ($signatureRaw === false) {
            return null;
        }

        $verified = openssl_verify(
            $headerB64 . '.' . $payloadB64,
            $signatureRaw,
            $keys[$kid],
            OPENSSL_ALGO_SHA256
        );
        if ($verified !== 1) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // Runtime binding: a token is only valid on the instance it was issued
        // for. Rejects copying data/license.json to another Symcon installation.
        if ($expectedSub !== null && ($payload['sub'] ?? null) !== $expectedSub) {
            return null;
        }

        return $payload;
    }

    private static function base64UrlDecode(string $input): string|false
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($input, '-_', '+/'), true);
    }
}
