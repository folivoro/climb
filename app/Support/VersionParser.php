<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parses Composer version strings into major version integers.
 *
 * ## Version string formats handled
 *
 * - Stable semver:     `1.2.3`, `v1.2.3`          → major int
 * - Numbered branches: `dev-2.x`, `dev-2.1`        → major int
 * - Everything else:   `dev-main`, `dev-master`,
 *                      `dev-feat/xxx`, `9999999-dev` → 1
 *
 * ## Rationale
 *
 * From Sloth v2 onwards, versioned branches follow the `{major}.x` convention.
 * Anything that cannot be unambiguously identified as v2+ is therefore v1.
 * This includes all legacy branch names (main, master, develop) as well as
 * feature branches — if it doesn't look like a numbered release, it's v1.
 *
 * @since 1.0.0
 */
class VersionParser
{
    /**
     * Parse a Composer version string into a major version integer.
     *
     * Always returns an integer — never null.
     *
     * @param  string $version Raw version string from composer.lock or installed.php.
     * @return int    Major version number.
     */
    public function majorVersion(string $version): int
    {
        $version = ltrim($version, 'v');

        // Numbered dev branch: dev-2.x, dev-2.1, dev-3, etc.
        if (preg_match('/^dev-(\d+)(?:[.\-]|$)/', $version, $matches)) {
            return (int) $matches[1];
        }

        // Stable semver: 1.2.3, 2.0.0 etc.
        if (preg_match('/^(\d+)\./', $version, $matches)) {
            return (int) $matches[1];
        }

        // Everything else (dev-main, dev-master, dev-feat/xxx, 9999999-dev, …) → v1
        return 1;
    }
}
