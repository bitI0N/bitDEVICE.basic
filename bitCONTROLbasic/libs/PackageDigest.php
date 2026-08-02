<?php

declare(strict_types=1);

/**
 * SHA-256 over the canonical file set of an extracted package.
 *
 * Recomputable from disk at any time, which is the whole point: the delivered
 * ZIP no longer exists after extraction, and ZIP archives are not
 * byte-reproducible across builds anyway. The hashed material is the sorted
 * list of (relative path, file hash) pairs, so content changes, additions,
 * removals, renames and moves all move the digest.
 *
 * Replaces `installed_checksum`, which hashed a single file on the client and
 * the whole ZIP on the server, in two different formats — a comparison that
 * could never match (finding D).
 */
final class PackageDigest
{
    private function __construct()
    {
    }

    /**
     * @return string 'sha256:<hex>' or '' when nothing could be hashed.
     *                '' is never a valid digest — callers compare for equality
     *                and therefore fail closed.
     */
    public static function compute(string $dir): string
    {
        if (!is_dir($dir)) {
            return '';
        }

        $entries = self::collect($dir, '');
        if ($entries === []) {
            return '';
        }

        // Byte-order sort, independent of locale and of readdir() order.
        sort($entries, SORT_STRING);

        $material = '';
        foreach ($entries as $relative) {
            $hash = @hash_file('sha256', $dir . '/' . $relative);
            if ($hash === false) {
                return '';
            }
            // NUL separates path from hash so no path can forge a field
            // boundary: a file named "ab" and a file "a" holding "b" must not
            // produce the same material.
            $material .= $relative . "\0" . $hash . "\n";
        }

        return 'sha256:' . hash('sha256', $material);
    }

    /**
     * Relative paths of every regular file below $dir, '/'-separated.
     *
     * Symlinks are skipped deliberately: following them would hash content
     * outside the package, and hashing the link itself would make the digest
     * depend on a target that can change afterwards.
     *
     * @return array<string>
     */
    private static function collect(string $dir, string $prefix): array
    {
        $names = @scandir($dir);
        if ($names === false) {
            return [];
        }

        $found = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $found = array_merge($found, self::collect($path, $relative));
                continue;
            }

            if (is_file($path)) {
                $found[] = $relative;
            }
        }

        return $found;
    }
}
