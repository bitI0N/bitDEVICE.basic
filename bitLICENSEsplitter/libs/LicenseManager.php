<?php

declare(strict_types=1);

class LicenseManager
{
    private string $dataPath;
    private string $keysPath;
    private string $serverUrl;
    private ?string $licensee;

    /** @var callable(string $method, string $path, ?array $body): ?array */
    private $httpTransport;

    private const GRACE_PERIOD_DAYS = 14;
    private const DEFAULT_SERVER_URL = 'https://license.bition.com/api/v1';

    /**
     * @param string|null $licensee Expected token subject (IPS_GetLicensee()).
     *                              When set, tokens whose `sub` does not match
     *                              are rejected at runtime, binding a license to
     *                              the Symcon instance it was issued for. Null
     *                              disables the binding (tooling / tests).
     */
    public function __construct(string $dataPath, ?callable $httpTransport = null, ?string $serverUrl = null, ?string $licensee = null)
    {
        $this->dataPath = $dataPath;
        $this->keysPath = dirname($dataPath) . '/libs/keys';
        $this->serverUrl = $serverUrl ?? self::DEFAULT_SERVER_URL;
        $this->httpTransport = $httpTransport;
        $this->licensee = $licensee;
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

            // Integrity is mandatory: refuse to extract a package that is not
            // covered by a signed checksum claim (fail closed, not open).
            if (!isset($payload['checksum']) || $payload['checksum'] === '') {
                return ['success' => false, 'error' => 'Package integrity claim missing.'];
            }
            $actualChecksum = 'sha256:' . hash('sha256', $zipData);
            if (!hash_equals($payload['checksum'], $actualChecksum)) {
                return ['success' => false, 'error' => 'Package checksum mismatch.'];
            }

            if (!$this->installPackage($zipData)) {
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
     * - On unreachable server: keeps the license (grace period handled by validate())
     * - On explicit revocation (server reports license_valid=false): wipes the
     *   local license and Pro code so the module falls back to Community
     *
     * @return array{success: bool, update_available?: bool, revoked?: bool, error?: string}
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
            // Server unreachable — keep the license; grace period handled by validate()
            return ['success' => false, 'error' => 'Server unreachable. Grace period continues.'];
        }

        if (!isset($response['token'])) {
            // Definitive revocation: the server reached us and rejected the license
            // (refund / chargeback / fraud / seat moved). Only this explicit signal
            // downgrades a perpetual license — transient errors keep it running.
            if (($response['license_valid'] ?? null) === false) {
                $this->clearLocalLicense();
                return [
                    'success' => false,
                    'revoked' => true,
                    'error'   => $response['error'] ?? 'License has been revoked.',
                ];
            }

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
            // Only extract when the package matches a signed checksum claim. A
            // missing claim or a mismatch leaves the current installation intact
            // (fail closed).
            if ($zipData !== null && isset($payload['checksum']) && $payload['checksum'] !== '') {
                $actualChecksum = 'sha256:' . hash('sha256', $zipData);
                if (hash_equals($payload['checksum'], $actualChecksum)) {
                    // Report an update only when one actually landed. Claiming
                    // success on a failed install hides the failure from the
                    // module, which would then reboot against a package that
                    // was never replaced.
                    $updateAvailable = $this->installPackage($zipData);
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
    public function deactivate(string $licensee = ''): void
    {
        if ($licensee !== '') {
            // Send the current token so the server can authenticate the seat
            // release (it verifies signature + sub == licensee).
            $state = $this->loadState();
            $token = is_array($state) ? ($state['token'] ?? '') : '';
            if ($token !== '') {
                $this->httpPost('/deactivate', ['licensee' => $licensee, 'token' => $token]);
            }
        }

        $this->clearLocalLicense();
    }

    /**
     * Remove the local license token and downloaded Pro package, without any
     * server notification. Used on deactivation and on server-side revocation.
     */
    private function clearLocalLicense(): void
    {
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
        $licensee     = '';
        $updatesUntil = null;
        $features     = [];

        $state = $this->loadState();
        if ($state !== null && !empty($state['token'])) {
            $payload = $this->verifyToken($state['token']);
            if ($payload !== null) {
                $licensee = $payload['sub'] ?? '';
                $updatesUntil = $payload['updates_until'] ?? null;
                $features = $payload['features'] ?? [];
            }
        }

        return [
            'state'        => $validation['state'],
            'tier'         => $validation['tier'],
            'licensee'     => $licensee,
            'updatesUntil' => $updatesUntil,
            'features'     => $features,
            'expires'      => $validation['expires'],
            'daysLeft'     => $validation['daysLeft'],
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

        // Runtime binding: a token is only valid on the instance it was issued
        // for. Reject a token whose subject does not match the expected licensee
        // (prevents copying data/license.json to another Symcon installation).
        if ($this->licensee !== null && ($payload['sub'] ?? null) !== $this->licensee) {
            return null;
        }

        return $payload;
    }

    /**
     * Load a PEM public key for the given key ID.
     *
     * The key file must exist at keys/<kid>.pub. Resolution is strict: the kid
     * from the token header selects exactly one key. There is deliberately no
     * "newest available key" fallback — during key rotation the client ships the
     * current and predecessor keys, and a token must be verified against the key
     * its kid names (or rejected).
     *
     * @return string|false  PEM string or false if the kid is unknown
     */
    private function loadPublicKey(string $kid): string|false
    {
        // Sanitise kid to prevent path traversal.
        $safekid = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $kid);
        if ($safekid === '') {
            return false;
        }

        $keyFile = $this->keysPath . '/' . $safekid . '.pub';
        if (!is_file($keyFile)) {
            return false;
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

        // The server only ever returns server-relative download paths. Build the
        // URL from the trusted base and refuse absolute URLs that point anywhere
        // other than the license-server origin — this blocks a tampered/hostile
        // response from redirecting the download to an attacker host or plain http.
        $baseUrl = preg_replace('#/api/v\d+$#', '', $this->serverUrl);
        if (str_starts_with($url, 'http')) {
            if ($url !== $baseUrl && !str_starts_with($url, $baseUrl . '/')) {
                return null;
            }
        } else {
            $url = $baseUrl . $url;
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
     * Install a downloaded package atomically.
     *
     * Extracts to a staging directory first and only promotes it once the
     * archive is known to be complete and safe. A failure at any stage leaves
     * the previously installed package exactly as it was — losing paid code
     * because an update went wrong is worse than not updating.
     */
    private function installPackage(string $zipData): bool
    {
        $proDir     = $this->dataPath . '/pro';
        $stagingDir = $this->dataPath . '/pro.staging';
        $backupDir  = $this->dataPath . '/pro.old';

        // Recover from a rotation interrupted between the two renames below
        // (process killed, OOM, power loss). If `pro` is missing while
        // `pro.old` exists, `pro.old` is not residue — it is the only
        // surviving copy of the previously installed package. Restore it
        // before anything else runs, so a failed or never-retried install
        // never leaves data/pro/ empty.
        if (!is_dir($proDir) && is_dir($backupDir)) {
            @rename($backupDir, $proDir);
        }

        // Clear residue from an earlier run that was interrupted mid-rotation.
        // `pro.old` is only disposable once `pro` demonstrably exists — either
        // because it was never touched, or because it was just recovered above.
        $this->removeDirectory($stagingDir);
        if (is_dir($proDir)) {
            $this->removeDirectory($backupDir);
        }

        if (!$this->extractToStaging($zipData, $stagingDir)) {
            $this->removeDirectory($stagingDir);
            return false;
        }

        // Rotate in two steps. rename() onto an existing directory fails on
        // Windows, which is a supported Symcon target, so the old package is
        // moved aside before the new one takes its place.
        if (is_dir($proDir) && !@rename($proDir, $backupDir)) {
            $this->removeDirectory($stagingDir);
            return false;
        }

        if (!@rename($stagingDir, $proDir)) {
            if (is_dir($backupDir)) {
                @rename($backupDir, $proDir);
            }
            $this->removeDirectory($stagingDir);
            return false;
        }

        $this->removeDirectory($backupDir);

        return true;
    }

    /**
     * Extract a package archive into a staging directory.
     *
     * Returns false unless every entry is safe, extraction succeeded, and the
     * result actually looks like a package.
     */
    private function extractToStaging(string $zipData, string $stagingDir): bool
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'bclicense_');
        if ($tmpFile === false) {
            return false;
        }

        try {
            if (file_put_contents($tmpFile, $zipData) === false) {
                return false;
            }

            if (!@mkdir($stagingDir, 0755, true)) {
                return false;
            }

            $zip = new ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                return false;
            }

            // Guard against Zip Slip: reject absolute paths, Windows drive
            // prefixes, and any '..' traversal segment before extraction.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || !$this->isSafeZipEntry($name)) {
                    $zip->close();
                    return false;
                }
            }

            $extracted = $zip->extractTo($stagingDir);
            $zip->close();

            if (!$extracted) {
                return false;
            }

            // A package without its manifest is not usable; refuse to promote it.
            return is_file($stagingDir . '/manifest.php');
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Whether a ZIP entry name is safe to extract (no absolute path, no drive
     * prefix, no parent-directory traversal).
     */
    private function isSafeZipEntry(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $name);

        // Absolute path or Windows drive prefix (e.g. "C:...").
        if ($normalized[0] === '/' || preg_match('#^[A-Za-z]:#', $normalized) === 1) {
            return false;
        }

        // Any '..' path segment.
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
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
