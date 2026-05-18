<?php

declare(strict_types=1);

namespace App\Modernizers\V2\Jobs;

use App\Modernizers\AbstractJob;

use function Laravel\Prompts\confirm;

/**
 * Removes Sloth v1 bootstrapping from bootstrap.php and wp-config.php.
 *
 * ## What this job does
 *
 * In Sloth v1, bootstrap.php was responsible for booting the framework:
 *
 * ```php
 * use Sloth\Core\Sloth;
 * $GLOBALS['sloth'] = Sloth::getInstance();
 * ```
 *
 * In Sloth v2, bootstrapping is handled by the Cecropia MU-plugin
 * (folivoro/cecropia) which calls `Application::configure()->boot()`
 * on the `after_setup_theme` hook. bootstrap.php no longer needs
 * to touch Sloth at all.
 *
 * This job:
 * 1. Removes Sloth v1 references from bootstrap.php
 * 2. Optionally removes bootstrap.php and wp-config.php from .gitignore
 *
 * Everything else in bootstrap.php (DIR_* constants, Dotenv, DB settings
 * etc.) is left completely untouched.
 *
 * @since 1.0.0
 */
class MigrateBootstrap extends AbstractJob
{
    /**
     * Patterns to remove from bootstrap.php.
     *
     * Each entry is a regex that matches a full line including its newline.
     *
     * @var list<string>
     */
    private const REMOVE_PATTERNS = [
        // use Sloth\Core\Application;
        '/^use Sloth\\\\Core\\\\Application;\n?/m',
        // use Sloth\Core\Sloth;
        '/^use Sloth\\\\Core\\\\Sloth;\n?/m',
        // $GLOBALS['sloth'] = Sloth::getInstance();
        '/^\$GLOBALS\[\'sloth\'\]\s*=\s*Sloth::getInstance\(\);\n?/m',
        // Comment block above Sloth instance registration
        '/^\/\*\n \* Globally register the instance\.\n \*\/\n?/m',
        // class_alias('\Sloth\Configure\Configure', 'Configure');
        '/^class_alias\s*\(\s*[\'"]\\\\?Sloth\\\\Configure\\\\Configure[\'"]\s*,\s*[\'"]Configure[\'"]\s*\);\n?/m',
        // Configure::boot();
        '/^Configure::boot\(\);\n?/m',
        // Comment block "Shorthand for Configure in env configs"
        '/^\/\*\*\n \* Shorthand for Configure in env configs\n \*\/\n?/m',
    ];

    /**
     * Files that should be tracked in Git after migration.
     *
     * @var list<string>
     */
    private const GIT_TRACKED_FILES = [
        'bootstrap.php',
        'wp-config.php',
        'sloth.php',
    ];

    /**
     * Obsolete Sloth v1 files to delete.
     *
     * sloth.php was the MU-plugin that bootstrapped Sloth v1. In v2 this
     * is replaced by folivoro/cecropia which is installed automatically
     * via composer/installers.
     *
     * @var list<string>
     */
    private const OBSOLETE_FILES = [
        'sloth.php',
    ];

    /**
     * Execute the bootstrap migration.
     *
     * @return array<string, list<string>>
     */
    public function __invoke(): array
    {
        $this->command->info('🥾 Migrating bootstrap.php...');

        $this->migrateBootstrap();
        $this->removeObsoleteFiles();
        $this->offerGitignoreCleanup();

        return $this->report;
    }

    /**
     * Find and delete obsolete Sloth v1 files throughout the project.
     *
     * Searches the entire project root recursively for files like sloth.php
     * that were part of the v1 MU-plugin bootstrapping. In v2 these are
     * replaced by folivoro/cecropia.
     *
     * Files found in vendor/ are skipped.
     */
    private function removeObsoleteFiles(): void
    {
        $root = $this->modernizer->getProjectRoot();

        foreach (self::OBSOLETE_FILES as $filename) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() !== $filename) {
                    continue;
                }

                // Skip vendor/
                if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                // Only delete files that match the known Sloth v1 bootstrap fingerprint
                if (!$this->isSlothBootstrapFile($file->getPathname())) {
                    continue;
                }

                $relativePath = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
                unlink($file->getPathname());
                $this->dropped("{$relativePath} removed (obsolete Sloth v1 bootstrap)");
            }
        }
    }

    /**
     * Check whether a file matches the known Sloth v1 bootstrap fingerprint.
     *
     * The old sloth.php always contained either Plugin::getInstance() or
     * Sloth::getInstance() — this is the reliable identifier across all v1
     * versions. Any other sloth.php (e.g. a theme file) is left untouched.
     *
     * @param string $path Absolute path to the file.
     */
    private function isSlothBootstrapFile(string $path): bool
    {
        $content = file_get_contents($path);

        return str_contains($content, 'Plugin::getInstance()')
            || str_contains($content, 'Sloth::getInstance()');
    }

    /**
     * Remove Sloth v1 references from bootstrap.php.
     */
    private function migrateBootstrap(): void
    {
        $file = $this->modernizer->getProjectRoot() . '/bootstrap.php';

        if (!file_exists($file)) {
            $this->manual('bootstrap.php not found — please migrate manually.');

            return;
        }

        $original = file_get_contents($file);
        $modified = $original;

        foreach (self::REMOVE_PATTERNS as $pattern) {
            $modified = preg_replace($pattern, '', $modified);
        }

        if ($modified === $original) {
            $this->migrated('bootstrap.php — no Sloth v1 references found');

            return;
        }

        file_put_contents($file, $modified);

        $this->migrated('bootstrap.php — Sloth v1 bootstrapping removed');
    }

    /**
     * Offer to remove bootstrap.php and wp-config.php from .gitignore.
     *
     * In v1 projects these files were typically gitignored because they
     * contained environment-specific configuration. In v2 they should be
     * tracked since all sensitive values live in .env.
     */
    private function offerGitignoreCleanup(): void
    {
        $gitignore = $this->modernizer->getProjectRoot() . '/.gitignore';

        if (!file_exists($gitignore)) {
            return;
        }

        $content  = file_get_contents($gitignore);
        $toRemove = [];

        foreach (self::GIT_TRACKED_FILES as $file) {
            if (preg_match('/^[^\n!#]*' . preg_quote($file, '/') . '$/m', $content)) {
                $toRemove[] = $file;
            }
        }

        if (empty($toRemove)) {
            return;
        }

        $fileList = implode(', ', $toRemove);

        $remove = confirm(
            label: "Remove {$fileList} from .gitignore?",
            default: true,
            hint: 'These files should be tracked in Git in Sloth v2 since all sensitive values live in .env.',
        );

        if (!$remove) {
            $this->manual("{$fileList} still in .gitignore — consider tracking them in Git.");

            return;
        }

        foreach ($toRemove as $file) {
            // Remove the line with optional leading slash, but not negations or comments
            $content = preg_replace('/^[^\n!#]*' . preg_quote($file, '/') . '$\n?/m', '', $content);
            $this->migrated("{$file} removed from .gitignore");
        }

        file_put_contents($gitignore, $content);
    }
}
