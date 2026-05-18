<?php

declare(strict_types=1);

namespace App\Modernizers\V2\Jobs;

use App\Modernizers\AbstractJob;

use function Laravel\Prompts\confirm;

/**
 * Updates composer dependencies for the Sloth v2 migration.
 *
 * ## What this job does
 *
 * 1. Removes any installed Sloth package (sixmonkey/sloth or folivoro/sloth,
 *    any version) and any Layotter package (hingst/layotter, folivoro/layotter)
 *    via `composer remove`.
 * 2. Requires `folivoro/sloth:^2.0` via `composer require`.
 * 3. Optionally requires `folivoro/layotter-bridge` (user is prompted).
 * 4. All operations run with `-W` (--with-all-dependencies) to resolve
 *    transitive dependency conflicts common in legacy projects.
 *
 * ## Why CLI and not the Composer PHP API
 *
 * The Composer PHP API (Factory, Installer) is designed for plugins running
 * inside a Composer process — not for programmatic require/remove from an
 * external tool. Using it correctly would require bootstrapping the full
 * Composer runtime, handling IO, and managing the event dispatcher. The CLI
 * is the supported and stable interface for this use case.
 *
 * ## Package detection
 *
 * Packages to remove are detected by scanning composer.json directly rather
 * than hardcoding a single package name. This makes the job agnostic to
 * which specific Sloth or Layotter variant is installed.
 *
 * @since 1.0.0
 */
class UpdateComposerPackages extends AbstractJob
{
    /**
     * Sloth package names to detect and remove, regardless of version.
     *
     * @var list<string>
     */
    private const SLOTH_PACKAGES = [
        'sixmonkey/sloth',
        'folivoro/sloth',
    ];

    /**
     * Layotter package names to detect and remove.
     * These come implicitly via folivoro/layotter-bridge in v2.
     *
     * @var list<string>
     */
    private const LAYOTTER_PACKAGES = [
        'hingst/layotter',
        'folivoro/layotter',
    ];

    private const NEW_SLOTH = 'folivoro/sloth:^2.0';
    private const BRIDGE_PACKAGE = 'folivoro/layotter-bridge:^2.0';
    private const CECROPIA_PACKAGE = 'folivoro/cecropia:^2.0';

    /**
     * Execute the composer package update.
     *
     * @return array<string, list<string>>
     */
    public function __invoke(): array
    {
        $this->command->info('📦 Updating Composer packages...');

        $composerJson = $this->modernizer->getProjectRoot() . '/composer.json';

        if (!file_exists($composerJson)) {
            $this->manual('composer.json not found — please update packages manually.');

            return $this->report;
        }

        $installed = $this->installedPackages($composerJson);

        $this->removePackages($installed);
        $this->updateComposerDependencies();
        $this->requireSloth();
        $this->requireCecropia();
        $this->offerLayotterBridge($installed);

        return $this->report;
    }

    /**
     * Read all package names currently declared in composer.json (require + require-dev).
     *
     * @param string $composerJson Absolute path to composer.json.
     * @return list<string> Flat list of package names.
     */
    private function installedPackages(string $composerJson): array
    {
        $data = json_decode(file_get_contents($composerJson), true);

        return array_keys(array_merge(
            $data['require'] ?? [],
            $data['require-dev'] ?? [],
        ));
    }

    /**
     * Remove all detected Sloth and Layotter packages in a single composer remove call.
     *
     * Uses -W so that transitive dependencies are updated alongside the removed packages,
     * preventing version conflicts common in legacy projects.
     *
     * @param list<string> $installed Currently declared package names.
     */
    private function removePackages(array $installed): void
    {
        $toRemove = array_values(array_filter(
            array_merge(self::SLOTH_PACKAGES, self::LAYOTTER_PACKAGES),
            fn($pkg) => in_array($pkg, $installed, true),
        ));

        if (empty($toRemove)) {
            $this->manual('No Sloth or Layotter packages found in composer.json — already removed?');

            return;
        }

        $packages = implode(' ', $toRemove);
        $exitCode = $this->composer("remove {$packages} -W --no-update --ansi");

        if ($exitCode === 0) {
            foreach ($toRemove as $pkg) {
                $this->dropped("{$pkg} removed");
            }
        } else {
            $this->manual("composer remove {$packages} failed — please remove manually.");
        }
    }

    /**
     * @return void
     */
    private function updateComposerDependencies(): void
    {
        $this->command->line('🧹 Doing a composer update for you…');

        $exitCode = $this->composer('update -W --prefer-stable --ansi');

        if ($exitCode === 0) {
            $this->migrated('Successfully did a composer upgrade');
        } else {
            $this->manual('Composer upgrade failed – please do it manually.');
        }
    }

    /**
     * Require folivoro/sloth:^2.0.
     *
     * Uses -W so that all transitive dependencies are reconsidered alongside
     * the new constraint.
     */
    private function requireSloth(): void
    {
        $this->command->line('  Requiring ' . self::NEW_SLOTH . '...');

        $exitCode = $this->composer('require ' . self::NEW_SLOTH . ' -W --sort-packages --ansi');

        if ($exitCode === 0) {
            $this->migrated(self::NEW_SLOTH . ' installed');
        } else {
            $this->manual(
                'composer require ' . self::NEW_SLOTH . ' failed — please run it manually.'
            );
        }
    }

    /**
     * Require folivoro/cecropia:^2.0.
     *
     * Uses -W so that all transitive dependencies are reconsidered alongside
     * the new constraint.
     */
    private function requireCecropia(): void
    {
        $this->command->line('  Requiring ' . self::CECROPIA_PACKAGE . '...');

        $exitCode = $this->composer('require ' . self::CECROPIA_PACKAGE . ' -W --sort-packages --ansi');

        if ($exitCode === 0) {
            $this->migrated(self::CECROPIA_PACKAGE . ' installed');
        } else {
            $this->manual(
                'composer require ' . self::CECROPIA_PACKAGE . ' failed — please run it manually.'
            );
        }
    }

    /**
     * Offer to install folivoro/layotter-bridge if any Layotter package was present.
     *
     * The bridge is only relevant when the project was using Layotter before.
     * If no Layotter package was found, the prompt is skipped entirely.
     *
     * @param list<string> $installed Currently declared package names.
     */
    private function offerLayotterBridge(array $installed): void
    {
        $hadLayotter = !empty(array_filter(
            self::LAYOTTER_PACKAGES,
            fn($pkg) => in_array($pkg, $installed, true),
        ));

        $install = confirm(
            label: 'Do you want to install folivoro/layotter-bridge?',
            default: $hadLayotter,
            hint: $hadLayotter
                ? 'Layotter was detected in this project — this bridge is likely required.'
                : 'Only needed if you use Layotter in this project.',
        );

        if (!$install) {
            return;
        }

        $exitCode = $this->composer('require ' . self::BRIDGE_PACKAGE . ' -W --sort-packages --ansi');

        if ($exitCode === 0) {
            $this->migrated(self::BRIDGE_PACKAGE . ' installed');
        } else {
            $this->manual(
                'composer require ' . self::BRIDGE_PACKAGE . ' failed — please install manually.'
            );
        }
    }

    /**
     * Shell out to composer in the project root.
     *
     * @param string $args Arguments to pass to the composer binary.
     * @return int    Exit code.
     */
    private function composer(string $args): int
    {
        $cwd = escapeshellarg($this->modernizer->getProjectRoot());
        passthru("cd {$cwd} && composer {$args}", $exitCode);

        return $exitCode;
    }
}
