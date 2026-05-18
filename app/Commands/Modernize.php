<?php

declare(strict_types=1);

namespace App\Commands;

use App\Modernizers\AbstractModernizer;
use App\Modernizers\V2\Modernizer as V2Modernizer;
use App\Support\VersionParser;
use LaravelZero\Framework\Commands\Command;

/**
 * The `climb modernize` command.
 *
 * ## Usage
 *
 * ```bash
 * climb modernize        # migrates to the highest available TO_VERSION
 * climb modernize --to=2 # explicit target
 * ```
 *
 * @since 1.0.0
 */
class Modernize extends Command
{
    protected $signature = 'modernize
        {--to= : Target Sloth major version}
    ';

    protected $description = 'Modernize a Sloth project to the latest version.';

    private const SLOTH_PACKAGES = [
        'sixmonkey/sloth',
        'folivoro/sloth',
    ];

    /**
     * @var array<string, class-string<AbstractModernizer>>
     */
    private const MODERNIZERS = [
        '1-2' => V2Modernizer::class,
    ];

    public function handle(): int
    {
        $this->line('');
        $this->line('  🧗 folivoro/climb — Sloth modernizer');
        $this->line('');

        if (!file_exists(getcwd() . '/composer.json')) {
            $this->error('❌ No composer.json found. Please run climb from the root of your project.');

            return self::FAILURE;
        }

        $fromVersion = $this->detectInstalledVersion();

        if ($fromVersion === null) {
            $this->error('❌ No Sloth package found. Make sure sixmonkey/sloth or folivoro/sloth is installed.');

            return self::FAILURE;
        }

        $toVersion = $this->resolveToVersion($fromVersion);

        if ($toVersion === null) {
            return self::FAILURE;
        }

        $key = "{$fromVersion}-{$toVersion}";

        if (!isset(self::MODERNIZERS[$key])) {
            $this->error(
                "❌ No modernizer available for v{$fromVersion} → v{$toVersion}. " .
                'Please check that you are running the latest version of folivoro/climb.'
            );

            return self::FAILURE;
        }

        if (!$this->confirmIfDowngrade($fromVersion, $toVersion)) {
            return self::FAILURE;
        }

        $this->info("  Migrating Sloth v{$fromVersion} → v{$toVersion}...");
        $this->line('');

        $modernizerClass = self::MODERNIZERS[$key];
        $modernizer      = new $modernizerClass($this);
        $modernizer->setup();
        $modernizer->run();
        $modernizer->report();

        return self::SUCCESS;
    }

    private function resolveToVersion(int $fromVersion): ?int
    {
        if ($this->option('to') !== null) {
            return (int) $this->option('to');
        }

        $available = collect(array_keys(self::MODERNIZERS))
            ->map(fn ($key) => explode('-', $key))
            ->filter(fn ($parts) => (int) $parts[0] === $fromVersion)
            ->map(fn ($parts) => (int) $parts[1])
            ->sort()
            ->values();

        if ($available->isEmpty()) {
            $this->error("❌ No modernizer available for Sloth v{$fromVersion}.");

            return null;
        }

        return $available->last();
    }

    /**
     * Detect the installed Sloth major version.
     *
     * Reads from vendor/composer/installed.php when available,
     * falls back to composer.lock for uncommitted vendor directories.
     * Returns null only when no Sloth package is found at all.
     */
    private function detectInstalledVersion(): ?int
    {
        $parser = new VersionParser();

        $installedFile = getcwd() . '/vendor/composer/installed.php';

        if (file_exists($installedFile)) {
            $versions = (require $installedFile)['versions'] ?? [];

            foreach (self::SLOTH_PACKAGES as $package) {
                if (isset($versions[$package])) {
                    return $parser->majorVersion($versions[$package]['version'] ?? '');
                }
            }

            return null;
        }

        $lockFile = getcwd() . '/composer.lock';

        if (!file_exists($lockFile)) {
            return null;
        }

        $lock     = json_decode(file_get_contents($lockFile), true);
        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

        foreach ($packages as $package) {
            if (in_array($package['name'] ?? '', self::SLOTH_PACKAGES, true)) {
                return $parser->majorVersion($package['version'] ?? '');
            }
        }

        return null;
    }

    private function confirmIfDowngrade(int $fromVersion, int $toVersion): bool
    {
        if ($fromVersion < $toVersion) {
            return true;
        }

        $this->warn("⚠️  Installed version (v{$fromVersion}) >= target (v{$toVersion}). This would be a downgrade.");

        return $this->confirm('Are you sure you want to continue?', false);
    }
}
