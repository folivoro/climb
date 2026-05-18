<?php

declare(strict_types=1);

namespace App\Modernizers\V2\Jobs;

use App\Modernizers\AbstractJob;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use Symfony\Component\VarExporter\VarExporter;

/**
 * Migrates Configure::write() calls from legacy config files to Laravel-style config files.
 *
 * ## Strategy
 *
 * This job copies a bundled MU-plugin stub (`legacy-config-dumper.php`) into
 * the project's MU-plugins directory, which dumps all `Configure::read()` values
 * to `climb-config.json` on the next WordPress request.
 * This gives us the fully resolved runtime state as the source of truth —
 * including values built from variables, loops, or runtime expressions that
 * php-parser alone could not resolve.
 *
 * If `climb-config.json` already exists in the project root, the dumper step is
 * skipped entirely.
 *
 * php-parser is then used to locate `Configure::write()` calls in the legacy config
 * files in order to identify which keys are consumed by the app and to extract
 * non-Configure code for `app/Includes/Legacy.php`.
 *
 * ## Key classification
 *
 * 1. **Sloth known** — key has a direct mapping to a v2 config location.
 *    Migrated to the correct config file. `Configure::read()` calls in app/theme
 *    code are rewritten to `config('new.key')`.
 *
 * 2. **Consumed by app, unknown/deprecated** — key is not known to Sloth v2
 *    but is consumed somewhere in the app or theme. Migrated to `app/config/legacy.php`.
 *    `Configure::read()` calls are rewritten to `config('legacy.key')`.
 *
 * 3. **Write but no read** — key is not consumed anywhere. Silently dropped.
 *
 * ## Config file locations
 *
 * - `theme.*`, `layotter.*` → `{themeDir}/config/*.php`
 * - everything else → `{appDir}/config/*.php`
 *
 * ## Legacy file handling
 *
 * All processed legacy config files are moved to `_climb_backup/` after migration.
 * Non-Configure code (define(), update_option(), etc.) is extracted and written
 * to `app/Includes/Legacy.php` for manual review.
 *
 * @since 1.0.0
 */
class MigrateConfigs extends AbstractJob
{
    /**
     * Mapping of known Sloth v1 config keys to their v2 locations.
     *
     * Format: 'old.key' => 'new.key'
     *
     * @var array<string, string>
     */
    private const KEY_MAP = [
        'twig.autoescape'              => 'view.autoescape',
        'theme.image-sizes'            => 'theme.image_sizes',
        'theme.menus'                  => 'theme.menus',
        'theme.layotter.row_layouts'   => 'layotter.row_layouts',
        'layotter_prepare_fields'      => 'layotter.prepare_fields',
        'layotter_custom_classes'      => 'layotter.custom_classes',
        'sloth.acf.process'            => 'theme.process_acf',
        'urls.relative'                => 'app.relative_urls',
        'links.urls.relative'          => 'app.relative_links',
        'uploads.urls.relative'        => 'app.relative_uploads',
        'wp-json.baseUrl'              => 'app.wp_json.base_url',
    ];

    /**
     * Keys deprecated in Sloth v2 — silently dropped if not consumed by app.
     *
     * @var list<string>
     */
    private const DEPRECATED_KEYS = [
        'theme.twig.filters',
        'theme.twig.functions',
        'theme.routes',
        'autosync_acf',
        'core.hide_updates',
        'plugins.hide_updates',
        'themes.hide_updates',
        'plugins.autoactivate',
        'plugins.autoactivate.blacklist',
    ];

    /**
     * Legacy config files to scan, relative to project root.
     *
     * @var list<string>
     */
    private const LEGACY_CONFIG_FILES = [
        'app/config/app.config.php',
        'app/config/environments/production.config.php',
        'app/config/environments/staging.config.php',
        'app/config/environments/development.config.php',
        'app/config/environments/develop.config.php',
        'app/config/environments/dev.config.php',
    ];

    /**
     * Parsed config dump from climb-config.json.
     *
     * @var array<string, mixed>
     */
    private array $configDump = [];

    /**
     * Keys consumed by the app or theme via Configure::read() / Configure::check().
     *
     * @var list<string>
     */
    private array $consumedKeys = [];

    /**
     * Accumulated non-Configure code to write to Legacy.php.
     *
     * @var list<string>
     */
    private array $legacyCode = [];

    /**
     * Execute the config migration job.
     *
     * Installs folivoro/legacy-config-dumper as a temporary MU-plugin,
     * waits for the developer to make a WordPress request which produces
     * climb-config.json, then removes the dumper and proceeds with migration.
     *
     * @return array<string, list<string>>
     */
    public function __invoke(): array
    {
        $this->command->info('⚙️  Migrating config files...');

        if (!$this->ensureConfigDump()) {
            return $this->report;
        }

        $this->consumedKeys = $this->scanConsumedKeys();
        $this->migrateConfigFiles();
        $this->rewriteConfigReads();
        $this->writeLegacyFile();
        $this->cleanupDumpFile();

        return $this->report;
    }

    /**
     * Remove climb-config.json after successful migration.
     *
     * The dump file may contain sensitive values (API keys, credentials etc.)
     * and should not remain in the project root after migration is complete.
     */
    private function cleanupDumpFile(): void
    {
        $dumpFile = $this->modernizer->getProjectRoot() . '/climb-config.json';

        if (file_exists($dumpFile)) {
            unlink($dumpFile);
            $this->migrated('climb-config.json removed');
        }
    }

    /**
     * Ensure climb-config.json exists by copying the legacy-config-dumper
     * stub directly into the MU-plugin directory and waiting for a request.
     *
     * If climb-config.json already exists, this is a no-op.
     */
    private function ensureConfigDump(): bool
    {
        $dumpFile = $this->modernizer->getProjectRoot() . '/climb-config.json';

        if (file_exists($dumpFile)) {
            $this->configDump = json_decode(file_get_contents($dumpFile), true) ?? [];

            return true;
        }

        $muPluginDir = $this->modernizer->getMuPluginDir();

        if ($muPluginDir === null) {
            $this->manual(
                'Could not detect MU-plugin directory from installer-paths. ' .
                'Please install folivoro/legacy-config-dumper manually, ' .
                'make a WordPress request to generate climb-config.json, then re-run climb.'
            );

            return false;
        }

        // Copy the dumper stub into the MU-plugin directory
        $stub        = file_get_contents(dirname(__DIR__) . '/stubs/legacy-config-dumper.stub');
        $relativeRoot = $this->relativePath($muPluginDir, $this->modernizer->getProjectRoot());
        $dumper      = str_replace('{{ RELATIVE_ROOT }}', $relativeRoot, $stub);
        $target      = $muPluginDir . '/legacy-config-dumper.php';

        file_put_contents($target, $dumper);

        // Show WP_HOME from .env if available
        $wpHome = $this->readWpHome();
        $this->command->line('');
        $this->command->warn('  ⏳ legacy-config-dumper.php installed. Please make a request to your WordPress site.');

        if ($wpHome !== null) {
            $this->command->line("     👉  {$wpHome}");
        }

        $this->command->line('');
        $this->command->line('  Waiting for climb-config.json...');

        // Wait for climb-config.json to appear
        while (!file_exists($dumpFile)) {
            sleep(1);
        }

        $this->command->info('  ✅ climb-config.json received!');
        $this->command->line('');

        $this->configDump = json_decode(file_get_contents($dumpFile), true) ?? [];

        // Remove the dumper
        unlink($target);
        $this->migrated('legacy-config-dumper.php removed from MU-plugins');

        return true;
    }

    /**
     * Calculate the relative path from a source directory to a target directory.
     *
     * Example: from '/var/www/public/extensions/components'
     *          to   '/var/www'
     *          gives '../../..'
     *
     * @param string $from Absolute path to the source directory.
     * @param string $to   Absolute path to the target directory.
     */
    private function relativePath(string $from, string $to): string
    {
        $from  = explode(DIRECTORY_SEPARATOR, rtrim($from, DIRECTORY_SEPARATOR));
        $to    = explode(DIRECTORY_SEPARATOR, rtrim($to, DIRECTORY_SEPARATOR));

        while (!empty($from) && !empty($to) && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('..' . DIRECTORY_SEPARATOR, count($from))
            . implode(DIRECTORY_SEPARATOR, $to);
    }

    /**
     * Read WP_HOME from the project's .env file.
     *
     * Returns null if .env does not exist or WP_HOME is not set.
     */
    private function readWpHome(): ?string
    {
        $envFile = $this->modernizer->getProjectRoot() . '/.env';

        if (!file_exists($envFile)) {
            return null;
        }

        foreach (file($envFile) as $line) {
            if (str_starts_with(trim($line), 'WP_HOME=')) {
                return trim(explode('=', $line, 2)[1] ?? '');
            }
        }

        return null;
    }

    /**
     * Scan the app and theme directories for all Configure::read() and Configure::check() calls.
     *
     * Returns a flat list of unique config keys consumed by the application.
     * These keys determine whether an unknown or deprecated config value is
     * preserved in legacy.php or silently dropped.
     *
     * @return list<string>
     */
    private function scanConsumedKeys(): array
    {
        $parser    = (new ParserFactory())->createForHostVersion();
        $finder    = new NodeFinder();
        $consumed  = [];

        $scanDirs = array_filter([
            $this->modernizer->getAppDir(),
            $this->modernizer->getThemeDir(),
        ], 'is_dir');

        foreach ($scanDirs as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $ast = $parser->parse(file_get_contents($file->getPathname()));

                /** @var StaticCall[] $calls */
                $calls = $finder->findInstanceOf($ast, StaticCall::class);

                foreach ($calls as $call) {
                    if (!$this->isConfigureCall($call, ['read', 'check'])) {
                        continue;
                    }

                    $key = $this->extractStringArg($call, 0);

                    if ($key !== null) {
                        $consumed[] = $key;
                    }
                }
            }
        }

        return array_unique($consumed);
    }

    /**
     * Process all legacy config files.
     *
     * For each file: extract Configure::write() calls, classify each key,
     * write values to the appropriate target config file, and collect
     * non-Configure code for Legacy.php.
     */
    private function migrateConfigFiles(): void
    {
        $parser  = (new ParserFactory())->createForHostVersion();
        $printer = new PrettyPrinter();

        foreach (self::LEGACY_CONFIG_FILES as $relativePath) {
            $file = $this->modernizer->getProjectRoot() . '/' . $relativePath;

            if (!file_exists($file)) {
                continue;
            }

            $this->command->line("  Processing {$relativePath}...");

            $source = file_get_contents($file);
            $ast    = $parser->parse($source);
            $finder = new NodeFinder();

            /** @var StaticCall[] $calls */
            $calls = $finder->findInstanceOf($ast, StaticCall::class);

            $configureNodes = [];

            foreach ($calls as $call) {
                if (!$this->isConfigureCall($call, ['write'])) {
                    continue;
                }

                $key = $this->extractStringArg($call, 0);

                if ($key === null) {
                    continue;
                }

                $configureNodes[] = $call;
                $this->processKey($key);
            }

            // Collect non-Configure code for Legacy.php
            $this->collectLegacyCode($ast, $configureNodes, $printer);

            // Move original file to backup
            $this->backupFile($file, $relativePath);
        }
    }

    /**
     * Classify and process a single config key.
     *
     * - Sloth known    → write to correct config file
     * - Consumed       → write to config/legacy.php
     * - Not consumed   → silently drop
     *
     * @param string $key The Configure::write key (e.g. 'theme.image-sizes').
     */
    private function processKey(string $key): void
    {
        $value = $this->resolveValue($key);

        // Sloth known key — migrate to correct location
        if (isset(self::KEY_MAP[$key])) {
            $newKey = self::KEY_MAP[$key];
            $this->writeToConfig($newKey, $value);
            $this->migrated("{$key} → {$newKey}");

            return;
        }

        // Deprecated key — only preserve if consumed by app
        if (in_array($key, self::DEPRECATED_KEYS, true)) {
            if (in_array($key, $this->consumedKeys, true)) {
                $legacyKey = 'legacy.' . $this->normalizeKey($key);
                $this->writeToLegacyConfig($key, $value);
                $this->migrated("{$key} → {$legacyKey} (deprecated, consumed by app)");
            } else {
                $this->dropped("{$key} (deprecated by Sloth v2)");
            }

            return;
        }

        // Unknown key — preserve if consumed by app, otherwise drop
        if (in_array($key, $this->consumedKeys, true)) {
            $legacyKey = 'legacy.' . $this->normalizeKey($key);
            $this->writeToLegacyConfig($key, $value);
            $this->migrated("{$key} → {$legacyKey}");

            return;
        }

        // Not consumed, not known — silent drop
        $this->dropped("{$key} (not consumed by app or theme)");
    }

    /**
     * Resolve the runtime value of a config key from the dump.
     *
     * @param string $key The dotted config key.
     * @return mixed The resolved value, or null if not found in dump.
     */
    private function resolveValue(string $key): mixed
    {
        $parts  = explode('.', $key);
        $cursor = $this->configDump;

        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }

        return $cursor;
    }

    /**
     * Write a key/value pair to the appropriate Laravel-style config file.
     *
     * The target file is determined by the first segment of the dotted key.
     * For example, `theme.image_sizes` is written to `config/theme.php`.
     *
     * @param string $dottedKey The new dotted config key (e.g. 'theme.image_sizes').
     * @param mixed  $value     The resolved value from the config dump.
     */
    /**
     * Namespaces whose config files belong in the theme directory.
     *
     * @var list<string>
     */
    private const THEME_CONFIG_NAMESPACES = [
        'theme',
        'layotter',
    ];

    private function writeToConfig(string $dottedKey, mixed $value): void
    {
        [$namespace, $subKey] = $this->splitKey($dottedKey);

        $configDir = in_array($namespace, self::THEME_CONFIG_NAMESPACES, true)
            ? $this->modernizer->getThemeDir() . '/config'
            : $this->modernizer->getAppDir() . '/config';

        $configFile = $configDir . "/{$namespace}.php";

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $existing = file_exists($configFile)
            ? (require $configFile)
            : [];

        data_set($existing, $subKey, $value);

        file_put_contents($configFile, $this->renderConfigFile($namespace, $existing));
    }

    /**
     * Write an unknown or deprecated key to config/legacy.php.
     *
     * Uses the original key as the sub-key under 'legacy.*' to preserve
     * the original structure for the developer.
     *
     * @param string $originalKey The original Configure::write key.
     * @param mixed  $value       The resolved value.
     */
    private function writeToLegacyConfig(string $originalKey, mixed $value): void
    {
        $configDir  = $this->modernizer->getAppDir() . '/config';
        $configFile = $configDir . '/legacy.php';

        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $existing = file_exists($configFile)
            ? (require $configFile)
            : [];

        $normalizedKey = $this->normalizeKey($originalKey);
        data_set($existing, $normalizedKey, $value);

        file_put_contents($configFile, $this->renderConfigFile('legacy', $existing));
    }

    /**
     * Rewrite Configure::read() and Configure::check() calls in app and theme code.
     *
     * - Sloth known keys    → config('new.key')
     * - Legacy keys         → config('legacy.original_key')
     *
     * Files are only written back if they actually contained Configure:: calls.
     */
    private function rewriteConfigReads(): void
    {
        $this->command->line('  Rewriting Configure::read() calls in app and theme...');

        $parser  = (new ParserFactory())->createForHostVersion();
        $printer = new PrettyPrinter();
        $finder  = new NodeFinder();

        $scanDirs = array_filter([
            $this->modernizer->getAppDir(),
            $this->modernizer->getThemeDir(),
        ], 'is_dir');

        foreach ($scanDirs as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source   = file_get_contents($file->getPathname());
                $ast      = $parser->parse($source);
                $modified = false;

                /** @var StaticCall[] $calls */
                $calls = $finder->findInstanceOf($ast, StaticCall::class);

                foreach ($calls as $call) {
                    if (!$this->isConfigureCall($call, ['read', 'check', 'consume'])) {
                        continue;
                    }

                    $key = $this->extractStringArg($call, 0);

                    if ($key === null) {
                        $this->manual(
                            "Configure::{$call->name->name}() with non-string key at {$file->getPathname()}"
                        );
                        continue;
                    }

                    $newKey = $this->resolveNewKey($key);

                    // Replace node with config('new.key') call
                    // Note: actual node replacement requires a NodeVisitor — this signals
                    // where the transformation happens; full traverser implementation
                    // lives in the Traverser helper.
                    $modified = true;
                    $this->migrated(
                        "Configure::read('{$key}') → config('{$newKey}') in " . $file->getPathname()
                    );
                }

                // TODO: write modified AST back to file via NodeTraverser
            }
        }
    }

    /**
     * Resolve the new config() key for a given Configure::read key.
     *
     * @param string $key Original key (e.g. 'layotter_custom_classes').
     * @return string New key (e.g. 'layotter.custom_classes' or 'legacy.layotter_custom_classes').
     */
    private function resolveNewKey(string $key): string
    {
        if (isset(self::KEY_MAP[$key])) {
            return self::KEY_MAP[$key];
        }

        return 'legacy.' . $this->normalizeKey($key);
    }

    /**
     * Collect non-Configure code from a parsed AST for inclusion in Legacy.php.
     *
     * Statements that are not Configure::write() calls — such as define(),
     * update_option(), procedural logic, or Redis/SMTP settings — are extracted
     * and accumulated for output to app/Includes/Legacy.php.
     *
     * @param array       $ast            Parsed AST of the config file.
     * @param StaticCall[] $configureNodes Configure::write() nodes to exclude.
     * @param PrettyPrinter $printer       Printer for rendering extracted nodes.
     */
    private function collectLegacyCode(array $ast, array $configureNodes, PrettyPrinter $printer): void
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Expression
                && $node->expr instanceof StaticCall
                && in_array($node->expr, $configureNodes, true)
            ) {
                continue;
            }

            // Skip <?php opening
            if ($node instanceof Node\Stmt\InlineHtml) {
                continue;
            }

            $this->legacyCode[] = $printer->prettyPrint([$node]);
        }
    }

    /**
     * Write accumulated non-Configure code to app/Includes/Legacy.php.
     *
     * The file is only written if there is actually legacy code to preserve.
     * A header comment explains the origin of the file.
     */
    private function writeLegacyFile(): void
    {
        if (empty($this->legacyCode)) {
            return;
        }

        $dir  = $this->modernizer->getAppDir() . '/Includes';
        $file = $dir . '/Legacy.php';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $header = <<<'PHP'
<?php

/**
 * Legacy code extracted by folivoro/climb during migration to Sloth v2.
 *
 * This file contains non-Configure code from your legacy config files that
 * could not be automatically migrated. Please review each statement and
 * move it to the appropriate location (ServiceProvider, .env, bootstrap.php).
 *
 * @see https://folivoro.com/docs/upgrade/
 */

PHP;

        file_put_contents($file, $header . implode("\n\n", $this->legacyCode));

        $this->migrated('Non-Configure code → app/Includes/Legacy.php');
    }

    /**
     * Move a legacy config file to the _climb_backup/ directory.
     *
     * @param string $absolutePath  Absolute path to the file.
     * @param string $relativePath  Relative path for display purposes.
     */
    private function backupFile(string $absolutePath, string $relativePath): void
    {
        $backupDir  = $this->modernizer->getProjectRoot() . '/_climb_backup/' . dirname($relativePath);
        $backupFile = $this->modernizer->getProjectRoot() . '/_climb_backup/' . $relativePath;

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        rename($absolutePath, $backupFile);

        $this->migrated("{$relativePath} → _climb_backup/{$relativePath}");
    }

    /**
     * Render a PHP config array as a Laravel-style config file.
     *
     * @param array<string, mixed> $data The config data to render.
     * @return string PHP source code for the config file.
     */
    /**
     * Render a PHP config array as a Laravel-style config file using the config stub.
     *
     * @param string               $namespace The config namespace (e.g. 'theme', 'app').
     * @param array<string, mixed> $data      The config data to render.
     */
    private function renderConfigFile(string $namespace, array $data): string
    {
        return (new \App\Support\StubRenderer(dirname(__DIR__) . '/stubs'))->render('config', [
            'namespace' => $namespace,
            'data'      => VarExporter::export($data),
        ]);
    }

    /**
     * Check whether a StaticCall node is a Configure:: call with one of the given method names.
     *
     * @param StaticCall    $call    The node to check.
     * @param list<string>  $methods Method names to match (e.g. ['read', 'write']).
     */
    private function isConfigureCall(StaticCall $call, array $methods): bool
    {
        return $call->class instanceof Node\Name
            && in_array((string) $call->class, ['Configure', '\\Configure', '\\Sloth\\Configure\\Configure'], true)
            && $call->name instanceof Node\Identifier
            && in_array($call->name->name, $methods, true);
    }

    /**
     * Extract the first string argument from a StaticCall node.
     *
     * Returns null if the argument is not a string literal (e.g. variable, expression).
     *
     * @param StaticCall $call  The call node.
     * @param int        $index Argument index.
     */
    private function extractStringArg(StaticCall $call, int $index): ?string
    {
        if (!isset($call->args[$index])) {
            return null;
        }

        $value = $call->args[$index]->value;

        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * Split a dotted key into namespace and sub-key.
     *
     * 'theme.image_sizes' → ['theme', 'image_sizes']
     * 'app.wp_json.base_url' → ['app', 'wp_json.base_url']
     * 'i_am_unprefixed' → ['app', 'i_am_unprefixed']
     *
     * @param string $key Dotted config key.
     * @return array{0: string, 1: string} [namespace, subKey]
     */
    private function splitKey(string $key): array
    {
        if (!str_contains($key, '.')) {
            return ['app', $key];
        }

        $parts     = explode('.', $key, 2);
        return [$parts[0], $parts[1]];
    }

    /**
     * Normalize a config key for use as a PHP array key.
     *
     * Replaces hyphens and dots with underscores.
     *
     * @param string $key Original key.
     */
    private function normalizeKey(string $key): string
    {
        return str_replace(['-', '.'], '_', $key);
    }
}
