<?php

declare(strict_types=1);

namespace App\Modernizers\V2;

use App\Modernizers\AbstractModernizer;
use App\Modernizers\V2\Jobs\MigrateBootstrap;
use App\Modernizers\V2\Jobs\MigrateConfigs;
use App\Modernizers\V2\Jobs\MigrateTypedProperties;
use App\Modernizers\V2\Jobs\MigrateViewExtensions;
use App\Modernizers\V2\Jobs\UpdateComposerJson;
use App\Modernizers\V2\Jobs\UpdateComposerPackages;
use Composer\Factory;
use Composer\IO\NullIO;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Modernizer for migrating Sloth v1 projects to v2.
 *
 * Orchestrates the following jobs in order:
 *
 * 1. UpdateComposerPackages  — swap sixmonkey/sloth for folivoro/sloth:^2.0
 * 2. MigrateConfigs          — migrate Configure::write() calls to Laravel-style config files
 * 3. MigrateViewExtensions   — generate AbstractViewExtension classes from legacy Twig registrations
 * 4. MigrateTypedProperties  — remove typed property declarations via Rector
 * 5. MigrateBootstrap        — update bootstrap.php and wp-config.php
 *
 * ## Setup
 *
 * Before running jobs, `setup()` resolves three paths:
 *
 * - `projectRoot` — directory containing composer.json (current working directory)
 * - `appDir`      — the app/ directory (conventionally `./app`, confirmed interactively)
 * - `themeDir`    — the active WordPress theme (derived from composer.json installer-paths,
 *                   or asked interactively when not detectable)
 *
 * @since 1.0.0
 */
class Modernizer extends AbstractModernizer
{
    /**
     * The Sloth version this modernizer migrates FROM.
     */
    public const int FROM_VERSION = 1;

    /**
     * Return the ordered list of jobs for the v1 → v2 migration.
     *
     * @return list<class-string>
     */
    public function getJobs(): array
    {
        return [
            MigrateConfigs::class,
            UpdateComposerJson::class,
            UpdateComposerPackages::class,
            MigrateViewExtensions::class,
            MigrateTypedProperties::class,
            MigrateBootstrap::class,
        ];
    }

    /**
     * Discover and validate project paths interactively.
     *
     * Reads composer.json to detect the theme directory from installer-paths.
     * Falls back to interactive prompts when paths cannot be detected.
     */
    public function setup(): void
    {
        $this->projectRoot = getcwd();

        $this->resolveAppDir();
        $this->resolveThemeDir();
        $this->resolveMuPluginDir();
    }

    /**
     * Resolve the app directory.
     *
     * Suggests `./app` as the default — the conventional location in all
     * Sloth projects — but confirms interactively and re-prompts on invalid input.
     */
    private function resolveAppDir(): void
    {
        do {
            $this->appDir = text(
                label: 'Where is your app directory?',
                default: './app',
                hint: 'Conventionally ./app in all Sloth projects.',
            );

            if (!is_dir($this->appDir)) {
                $this->command->error('🤔 Directory not found: ' . $this->appDir);
            }
        } while (!is_dir($this->appDir));

        $this->appDir = realpath($this->appDir);
    }

    /**
     * Resolve the active theme directory.
     *
     * Attempts to read the themes directory from composer.json installer-paths.
     * If that fails, asks the user directly. When multiple themes are found,
     * presents a select prompt. Only directories containing a style.css are
     * considered valid WordPress themes.
     */
    private function resolveThemeDir(): void
    {
        $themesDir = $this->detectThemesDirFromComposer();

        if ($themesDir === null) {
            $themesDir = $this->askForThemesDir();
        }

        $themes = $this->findThemes($themesDir);

        if (empty($themes)) {
            $this->command->error('No WordPress themes found in ' . $themesDir . '. Please check your theme directory.');
            $themesDir = $this->askForThemesDir();
            $themes = $this->findThemes($themesDir);
        }

        $this->themeDir = count($themes) === 1
            ? $themes[0]
            : select(
                label: 'Which theme do you want to modernize?',
                options: array_combine($themes, array_map('basename', $themes)),
            );

        $this->themeDir = realpath($this->themeDir);
    }

    /**
     * Attempt to detect the themes directory from composer.json installer-paths.
     *
     * Looks for an entry whose conditions include `type:wordpress-theme` and
     * returns the parent directory of the matched path pattern.
     *
     * Returns null if composer.json cannot be read or contains no theme installer path.
     */
    private function detectThemesDirFromComposer(): ?string
    {
        try {
            $composer = Factory::create(new NullIO(), $this->projectRoot . '/composer.json');
            $installerPaths = $composer->getPackage()->getExtra()['installer-paths'] ?? [];

            $themePath = collect($installerPaths)
                ->filter(fn($conditions) => in_array('type:wordpress-theme', $conditions, true))
                ->keys()
                ->first();

            if ($themePath === null) {
                return null;
            }

            $dir = dirname((string)$themePath);

            return is_dir($dir) ? $dir : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Ask the user to provide the themes directory interactively.
     *
     * Re-prompts until a valid directory is given.
     */
    private function askForThemesDir(): string
    {
        do {
            $dir = text(
                label: 'Where is your themes directory?',
                default: './public/wp-content/themes',
            );

            if (!is_dir($dir)) {
                $this->command->error('🤔 Directory not found: ' . $dir);
            }
        } while (!is_dir($dir));

        return $dir;
    }

    /**
     * Find valid WordPress theme directories within the given path.
     *
     * A directory is considered a valid theme if it contains a `style.css` file,
     * which is required by WordPress for theme registration.
     *
     * @param string $themesDir Absolute or relative path to the themes directory.
     * @return list<string> Absolute paths to valid theme directories.
     */
    private function findThemes(string $themesDir): array
    {
        return collect(glob($themesDir . '/*', GLOB_ONLYDIR))
            ->filter(fn($path) => file_exists($path . '/style.css'))
            ->values()
            ->all();
    }

    /**
     * Resolve the MU-plugins directory from composer.json installer-paths.
     *
     * Sets $this->muPluginDir to the resolved path, or null if not detectable.
     */
    private function resolveMuPluginDir(): void
    {
        try {
            $composer       = Factory::create(new NullIO(), $this->projectRoot . '/composer.json');
            $installerPaths = $composer->getPackage()->getExtra()['installer-paths'] ?? [];

            $path = collect($installerPaths)
                ->filter(fn ($conditions) => in_array('type:wordpress-muplugin', $conditions, true))
                ->keys()
                ->first();

            if ($path === null) {
                return;
            }

            $dir = rtrim(str_replace('{$name}', '', $path), '/\\');

            $this->muPluginDir = realpath($this->projectRoot . '/' . $dir) ?: null;
        } catch (\Throwable) {
            $this->muPluginDir = null;
        }
    }
}
