<?php

declare(strict_types=1);

namespace App\Modernizers\V2\Jobs;

use App\Modernizers\AbstractJob;

/**
 * Removes typed property declarations from Sloth Model and Taxonomy classes.
 *
 * ## Background
 *
 * PHP 8.4 raises a fatal error when a child class redeclares a property with
 * a type that the parent (Corcel) declared without one. Sloth v2 therefore
 * requires registration properties ($names, $options, $labels etc.) to remain
 * intentionally untyped.
 *
 * ## Strategy
 *
 * Delegates to Rector using climb's own rule set in `app/Rector/config.php`.
 * Because climb is distributed as a PHAR, Rector and the rule are bundled
 * inside the binary — no dependency on the target project's vendor directory.
 *
 * The config can also be run standalone by developers:
 *
 * ```bash
 * vendor/bin/rector process app/ --config vendor/folivoro/climb/app/Rector/config.php
 * ```
 *
 * @since 1.0.0
 */
class MigrateTypedProperties extends AbstractJob
{
    /**
     * Execute the typed properties migration via Rector.
     *
     * @return array<string, list<string>>
     */
    public function __invoke(): array
    {
        $this->command->info('🏛️  Removing typed property declarations via Rector...');

        $rectorBin    = $this->resolveRectorBin();
        $rectorConfig = dirname(__DIR__) . '/Rector/config.php';
        $appDir       = escapeshellarg($this->modernizer->getAppDir());
        $themeDir     = escapeshellarg($this->modernizer->getThemeDir());

        if ($rectorBin === null) {
            $this->manual(
                'rector binary not found — please run `composer install` in climb first.'
            );

            return $this->report;
        }

        $exitCode = 0;
        passthru("{$rectorBin} process {$appDir} {$themeDir} --config {$rectorConfig} --ansi", $exitCode);

        if ($exitCode === 0) {
            $this->migrated('Typed property declarations removed via Rector');
        } else {
            $this->manual(
                'Rector exited with errors — please review the output above.'
            );
        }

        return $this->report;
    }

    /**
     * Resolve the Rector binary path.
     *
     * When running as a PHAR, Rector is bundled inside the binary and
     * accessible via the vendor directory relative to climb's own root.
     * Falls back to a global `rector` binary on PATH.
     */
    private function resolveRectorBin(): ?string
    {
        // When running from source or PHAR-extracted vendor
        $local = dirname(__DIR__, 4) . '/vendor/bin/rector';
        if (file_exists($local)) {
            return escapeshellarg($local);
        }

        // Global rector on PATH
        exec('which rector 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output[0])) {
            return escapeshellarg(trim($output[0]));
        }

        return null;
    }
}
