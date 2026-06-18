<?php

declare(strict_types=1);

class LicenseManager
{
    private string $dataPath;
    private string $keysPath;
    private string $serverUrl = 'https://license.bition.com/api/v1';

    /** @var callable(string $method, string $path, ?array $body): ?array */
    private $httpTransport;

    private const GRACE_PERIOD_DAYS = 14;

    public function __construct(string $dataPath, ?callable $httpTransport = null)
    {
        $this->dataPath = $dataPath;
        $this->keysPath = dirname($dataPath) . '/libs/keys';
        $this->httpTransport = $httpTransport;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Activate a license key for the given licensee.
     *
     * - POSTs to /activate
     * - Verifies the returned JWT signature and sub claim
     * - Downloads the pro package ZIP from the returned download_url
     * - Verifies the ZIP checksum against the token payload
     * - Extracts the ZIP to data/pro/
     * - Saves the token to data/license.json
     *
     * @return array{success: bool, tier?: string, error?: string}
     */
    public function activate(string $licenseKey, string $licensee): array
    {
        $response = $this->httpPost('/activate', [
            'license_key'    => $licenseKey,
            'licensee'       => $licensee,
            'module_version' => $this->getModuleVersion(),
        ]);

        if ($response === null) {
            return ['success' => false, 'error' => 'Server unreachable. Please check your internet connection.'];
        }

        if (!isset($response['token'])) {
            $error = $response['error'] ?? 'Invalid server response.';
            return ['success' => false, 'error' => $error];
        }

        $payload = $this->verifyToken($response['token']);
        if ($payload === null) {
            return ['success' => false, 'error' => 'Token signature verification failed.'];
        }

        if (($payload['sub'] ?? '') !== $licensee) {
            return ['success' => false, 'error' => 'Token subject does not match licensee.'];
        }

        if (isset($response['download_url'])) {
            $zipData = $this->httpGet($response['download_url']);
            if ($zipData === null) {
                return ['success' => false, 'error' => 'Failed to download license package.'];
            }

            if (isset($payload['checksum'])) {
                $actualChecksum = hash('sha256', $zipData);
                if (!hash_equals($payload['checksum'], $actualChecksum)) {
                    return ['success' => false, 'error' => 'Package checksum mismatch.'];
                }
            }

            if (!$this->extractZip($zipData)) {
                return ['success' => false, 'error' => 'Failed to extract license package.'];
            }
        }

        $this->saveState($response['token']);

        $tier = $payload['tier'] ?? 'community';
        return ['success' => true, 'tier' => $tier];
    }

    /**
     * Validate the locally stored token.
     *
     * Does not make network requests. Checks the token signature and expiry,
     * applying the 14-day grace period when the token has expired.
     *
     * @return array{state: string, tier: string, expires: string|null, daysLeft: int|null}
     */
    public function validate(): array
    {
        $state = $this->loadState();

        if ($state === null || empty($state['token'])) {
            return [
                'state'   => 'community',
                'tier'    => 'community',
                'expires' => null,
                'daysLeft' => null,
            ];
        }

        $payload = $this->verifyToken($state['token']);
        if ($payload === null) {
            return [
                'state'   => 'community',
                'tier'    => 'community',
                'expires' => null,
                'daysLeft' => null,
            ];
        }

        $now     = time();
        $exp     = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        $tier    = $payload['tier'] ?? 'community';
        $expires = $exp > 0 ? date('Y-m-d', $exp) : null;

        if ($exp === 0 || $now <= $exp) {
            // Token valid and not expired
            $daysLeft = $exp > 0 ? (int) ceil(($exp - $now) / 86400) : null;
            return [
                'state'   => 'active',
                'tier'    => $tier,
                'expires' => $expires,
                'daysLeft' => $daysLeft,
            ];
        }

        // Token has expired — evaluate grace period
        $graceStart = isset($state['grace_start']) && $state['grace_start'] !== null
            ? (int) $state['grace_start']
            : null;

        if ($graceStart === null) {
            // First time we notice expiry — record grace start
            $graceStart = $now;
            $state['grace_start'] = $graceStart;
            $this->writeStateFile($state);
        }

        $graceEnd    = $graceStart + (self::GRACE_PERIOD_DAYS * 86400);
        $graceLeft   = (int) ceil(($graceEnd - $now) / 86400);

        if ($now < $graceEnd) {
            return [
                'state'   => 'grace',
                'tier'    => $tier,
                'expires' => $expires,
                'daysLeft' => max(0, $graceLeft),
            ];
        }

        return [
            'state'   => 'expired',
            'tier'    => $tier,
            'expires' => $expires,
            'daysLeft' => 0,
        ];
    }

    /**
     * Revalidate the license against the server.
     *
     * - POSTs to /revalidate with the current token, licensee, and installed checksum
     * - On success: saves the new token; downloads updated package if available
     * - On failure: starts / continues the grace period
     *
     * @return array{success: bool, update_available?: bool, error?: string}
     */
    public function revalidate(string $licensee): array
    {
        $state = $this->loadState();
        if ($state === null || empty($state['token'])) {
            return ['success' => false, 'error' => 'No active license to revalidate.'];
        }

        $installedChecksum = $this->getInstalledChecksum();

        $response = $this->httpPost('/revalidate', [
            'token'              => $state['token'],
            'licensee'           => $licensee,
            'module_version'     => $this->getModuleVersion(),
            'installed_checksum' => $installedChecksum,
        ]);

        if ($response === null) {
            // Server unreachable — grace period handled by validate()
            return ['success' => false, 'error' => 'Server unreachable. Grace period continues.'];
        }

        if (!isset($response['token'])) {
            $error = $response['error'] ?? 'Invalid server response.';
            return ['success' => false, 'error' => $error];
        }

        $payload = $this->verifyToken($response['token']);
        if ($payload === null) {
            return ['success' => false, 'error' => 'Revalidation token signature verification failed.'];
        }

        $updateAvailable = false;

        if (isset($response['download_url'])) {
            $zipData = $this->httpGet($response['download_url']);
            if ($zipData !== null) {
                if (isset($payload['checksum'])) {
                    $actualChecksum = hash('sha256', $zipData);
                    if (hash_equals($payload['checksum'], $actualChecksum)) {
                        $this->extractZip($zipData);
                        $updateAvailable = true;
                    }
                } else {
                    $this->extractZip($zipData);
                    $updateAvailable = true;
                }
            }
        }

        // Reset grace period on successful revalidation
        $this->saveState($response['token']);

        return ['success' => true, 'update_available' => $updateAvailable];
    }

    /**
     * Deactivate the license.
     *
     * Best-effort server notification. Always cleans up local files.
     */
    public function deactivate(): void
    {
        $state = $this->loadState();

        if ($state !== null && !empty($state['token'])) {
            // Best-effort — ignore server errors or unreachability
            $this->httpPost('/deactivate', ['token' => $state['token']]);
        }

        $licenseFile = $this->dataPath . '/license.json';
        if (file_exists($licenseFile)) {
            @unlink($licenseFile);
        }

        $proDir = $this->dataPath . '/pro';
        if (is_dir($proDir)) {
            $this->removeDirectory($proDir);
        }
    }

    /**
     * Return a high-level status array suitable for UI display.
     *
     * @return array{state: string, tier: string, licensee: string, expires: string|null, daysLeft: int|null}
     */
    public function getStatus(): array
    {
        $validation = $this->validate();
        $licensee   = '';

        $state = $this->loadState();
        if ($state !== null && !empty($state['token'])) {
            $payload = $this->verifyToken($state['token']);
            if ($payload !== null) {
                $licensee = $payload['sub'] ?? '';
            }
        }

        return [
            'state'    => $validation['state'],
            'tier'     => $validation['tier'],
            'licensee' => $licensee,
            'expires'  => $validation['expires'],
            'daysLeft' => $validation['daysLeft'],
        ];
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Verify a JWT RS256 token and return its decoded payload, or null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $headerJson = $this->base64UrlDecode($headerB64);
        if ($headerJson === false) {
            return null;
        }

        $header = json_decode($headerJson, true);
        if (!is_array($header)) {
            return null;
        }

        $alg = $header['alg'] ?? '';
        if ($alg !== 'RS256') {
            return null;
        }

        $kid    = $header['kid'] ?? 'default';
        $pubKey = $this->loadPublicKey((string) $kid);
        if ($pubKey === false) {
            return null;
        }

        $signingInput = $headerB64 . '.' . $payloadB64;

        $signatureRaw = $this->base64UrlDecode($signatureB64);
        if ($signatureRaw === false) {
            return null;
        }

        $result = openssl_verify($signingInput, $signatureRaw, $pubKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * Load a PEM public key for the given key ID.
     *
     * The key file is expected at keys/<kid>.pub.
     *
     * @return string|false  PEM string or false if not found
     */
    private function loadPublicKey(string $kid): string|false
    {
        // Sanitise kid to prevent path traversal
        $safekid = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $kid);
        $keyFile = $this->keysPath . '/' . $safekid . '.pub';

        if (!file_exists($keyFile)) {
            // Fall back to the most recent key file available
            $keyFiles = glob($this->keysPath . '/*.pub');
            if (empty($keyFiles)) {
                return false;
            }
            sort($keyFiles);
            $keyFile = end($keyFiles);
        }

        $pem = file_get_contents($keyFile);
        return $pem !== false ? $pem : false;
    }

    /**
     * Read data/license.json and return its decoded content.
     *
     * @return array<string, mixed>|null
     */
    private function loadState(): ?array
    {
        $file = $this->dataPath . '/license.json';
        if (!file_exists($file)) {
            return null;
        }

        $json = file_get_contents($file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Save a new token to data/license.json, resetting the grace period.
     */
    private function saveState(string $token): void
    {
        $this->writeStateFile([
            'token'          => $token,
            'last_validated' => time(),
            'grace_start'    => null,
        ]);
    }

    /**
     * Write the state array to data/license.json.
     *
     * @param array<string, mixed> $state
     */
    private function writeStateFile(array $state): void
    {
        if (!is_dir($this->dataPath)) {
            @mkdir($this->dataPath, 0755, true);
        }

        $file = $this->dataPath . '/license.json';
        @file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Perform an HTTP POST to the license server.
     *
     * Uses file_get_contents() with a stream context (Symcon's PHP has allow_url_fopen=On).
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>|null  Decoded JSON response or null on network/HTTP error
     */
    private function httpPost(string $path, array $data): ?array
    {
        if ($this->httpTransport) {
            return ($this->httpTransport)('POST', $path, $data);
        }

        $url     = $this->serverUrl . $path;
        $body    = json_encode($data);
        $context = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'         => $body,
                'timeout'         => 15,
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function httpGet(string $url): ?string
    {
        if ($this->httpTransport) {
            $result = ($this->httpTransport)('GET', $url, null);
            return is_string($result) ? $result : (is_array($result) ? json_encode($result) : null);
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        return $data !== false ? $data : null;
    }

    /**
     * Write ZIP binary to a temp file, extract its contents to data/pro/, and clean up.
     */
    private function extractZip(string $zipData): bool
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'bclicense_');
        if ($tmpFile === false) {
            return false;
        }

        try {
            if (file_put_contents($tmpFile, $zipData) === false) {
                return false;
            }

            $proDir = $this->dataPath . '/pro';
            if (!is_dir($proDir)) {
                if (!@mkdir($proDir, 0755, true)) {
                    return false;
                }
            }

            $zip = new ZipArchive();
            $result = $zip->open($tmpFile);
            if ($result !== true) {
                return false;
            }

            $extracted = $zip->extractTo($proDir);
            $zip->close();

            return $extracted;
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Read the module version from module.json.
     */
    private function getModuleVersion(): string
    {
        $moduleJson = dirname($this->dataPath) . '/module.json';
        if (!file_exists($moduleJson)) {
            return '0.0.0';
        }

        $data = json_decode((string) file_get_contents($moduleJson), true);
        return is_array($data) ? ($data['version'] ?? '0.0.0') : '0.0.0';
    }

    /**
     * Compute the SHA-256 checksum of the currently installed pro package.
     *
     * Falls back to an empty string if no package is installed.
     */
    private function getInstalledChecksum(): string
    {
        $manifestFile = $this->dataPath . '/pro/manifest.php';
        if (!file_exists($manifestFile)) {
            return '';
        }

        $hash = hash_file('sha256', $manifestFile);
        return $hash !== false ? $hash : '';
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Decode a base64url-encoded string.
     *
     * JWT uses base64url (RFC 4648 §5): '+' → '-', '/' → '_', no padding.
     *
     * @return string|false
     */
    private function base64UrlDecode(string $input): string|false
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded;
    }
}
