<?php

declare(strict_types=1);

use App\Modernizers\AbstractModernizer;
use App\Modernizers\V2\Jobs\MigrateConfigs;
use Symfony\Component\Console\Command\Command;

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function migrateConfigsSetup(): array
{
    $tmpDir = sys_get_temp_dir() . '/climb-test-' . uniqid();
    mkdir($tmpDir . '/app/config/environments', 0755, true);
    mkdir($tmpDir . '/config', 0755, true);

    $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
    $modernizer = Mockery::mock(AbstractModernizer::class);
    $modernizer->allows('getProjectRoot')->andReturn($tmpDir);
    $modernizer->allows('getAppDir')->andReturn($tmpDir . '/app');
    $modernizer->allows('getThemeDir')->andReturn($tmpDir . '/theme');

    return [$tmpDir, $command, $modernizer];
}

function writeConfigDump(string $tmpDir, array $data): void
{
    file_put_contents($tmpDir . '/climb-config.json', json_encode($data));
}

function writeLegacyConfig(string $tmpDir, string $body): void
{
    file_put_contents(
        $tmpDir . '/app/config/app.config.php',
        "<?php\n\n{$body}\n"
    );
}

function writeAppFile(string $tmpDir, string $body): void
{
    @mkdir($tmpDir . '/app/Http', 0755, true);
    file_put_contents($tmpDir . '/app/Http/SomeClass.php', "<?php\n\n{$body}\n");
}

function assertConfigFileContains(string $tmpDir, string $namespace, string $key, mixed $expected): void
{
    $file = $tmpDir . "/config/{$namespace}.php";
    expect($file)->toBeFile();
    expect(data_get(require $file, $key))->toEqual($expected);
}

function removeTmpDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

afterEach(fn () => Mockery::close());

// -------------------------------------------------------------------------
// Config dump loading
// -------------------------------------------------------------------------

describe('config dump loading', function () {
    it('aborts when no config dump is present', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        $command->shouldReceive('error')->once();

        $report = (new MigrateConfigs($command, $modernizer))();

        expect($report['migrated'])->toBeEmpty();
        expect($report['dropped'])->toBeEmpty();
    })->after(fn () => removeTmpDir($GLOBALS['tmpDir'] ?? ''));

    it('loads config dump from project root', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['theme' => ['menus' => ['primary' => 'Primary']]]);
        writeLegacyConfig($tmpDir, "Configure::write('theme.menus', ['primary' => 'Primary']);");

        $report = (new MigrateConfigs($command, $modernizer))();

        expect($report['migrated'])->toContain('theme.menus → theme.menus');
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Sloth known keys
// -------------------------------------------------------------------------

describe('sloth known keys', function () {
    it('migrates known keys to the correct config file', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['urls' => ['relative' => true]]);
        writeLegacyConfig($tmpDir, "Configure::write('urls.relative', true);");

        (new MigrateConfigs($command, $modernizer))();

        assertConfigFileContains($tmpDir, 'app', 'relative_urls', true);
        removeTmpDir($tmpDir);
    });

    it('migrates layotter_custom_classes', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        $classes = ['1/1' => 'col-12', '1/2' => 'col-6'];
        writeConfigDump($tmpDir, ['layotter_custom_classes' => $classes]);
        writeLegacyConfig($tmpDir, "Configure::write('layotter_custom_classes', \$classes);");

        (new MigrateConfigs($command, $modernizer))();

        assertConfigFileContains($tmpDir, 'layotter', 'custom_classes', $classes);
        removeTmpDir($tmpDir);
    });

    it('migrates sloth.acf.process to theme.process_acf', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['sloth' => ['acf' => ['process' => true]]]);
        writeLegacyConfig($tmpDir, "Configure::write('sloth.acf.process', true);");

        (new MigrateConfigs($command, $modernizer))();

        assertConfigFileContains($tmpDir, 'theme', 'process_acf', true);
        removeTmpDir($tmpDir);
    });

    it('migrates wp-json.baseUrl to app.wp_json.base_url', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['wp-json' => ['baseUrl' => 'api']]);
        writeLegacyConfig($tmpDir, "Configure::write('wp-json.baseUrl', 'api');");

        (new MigrateConfigs($command, $modernizer))();

        assertConfigFileContains($tmpDir, 'app', 'wp_json.base_url', 'api');
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Deprecated keys
// -------------------------------------------------------------------------

describe('deprecated keys', function () {
    it('silently drops deprecated keys not consumed by app', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['plugins' => ['autoactivate' => true]]);
        writeLegacyConfig($tmpDir, "Configure::write('plugins.autoactivate', true);");

        $report = (new MigrateConfigs($command, $modernizer))();

        expect($report['dropped'])->toContain('plugins.autoactivate (deprecated by Sloth v2)');
        expect($tmpDir . '/config/plugins.php')->not->toBeFile();
        removeTmpDir($tmpDir);
    });

    it('migrates deprecated keys consumed by app to legacy', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['plugins' => ['autoactivate' => true]]);
        writeLegacyConfig($tmpDir, "Configure::write('plugins.autoactivate', true);");
        writeAppFile($tmpDir, "Configure::read('plugins.autoactivate');");

        $report = (new MigrateConfigs($command, $modernizer))();

        expect($report['migrated'])->toContain(
            'plugins.autoactivate → legacy.plugins_autoactivate (deprecated, consumed by app)'
        );
        assertConfigFileContains($tmpDir, 'legacy', 'plugins_autoactivate', true);
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Unknown keys
// -------------------------------------------------------------------------

describe('unknown keys', function () {
    it('drops unknown keys not consumed by app', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['smr' => ['use_seo_features' => true]]);
        writeLegacyConfig($tmpDir, "Configure::write('smr.use_seo_features', true);");

        $report = (new MigrateConfigs($command, $modernizer))();

        expect($report['dropped'])->toContain('smr.use_seo_features (not consumed by app or theme)');
        removeTmpDir($tmpDir);
    });

    it('migrates unknown keys consumed by app to legacy', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['smr' => ['use_seo_features' => true]]);
        writeLegacyConfig($tmpDir, "Configure::write('smr.use_seo_features', true);");
        writeAppFile($tmpDir, "Configure::read('smr.use_seo_features');");

        $report = (new MigrateConfigs($command, $modernizer))();

        expect($report['migrated'])->toContain('smr.use_seo_features → legacy.smr_use_seo_features');
        assertConfigFileContains($tmpDir, 'legacy', 'smr_use_seo_features', true);
        removeTmpDir($tmpDir);
    });

    it('handles unprefixed keys as app namespace', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['i_am_funny' => 12]);
        writeLegacyConfig($tmpDir, "Configure::write('i_am_funny', 12);");
        writeAppFile($tmpDir, "Configure::read('i_am_funny');");

        (new MigrateConfigs($command, $modernizer))();

        assertConfigFileContains($tmpDir, 'legacy', 'i_am_funny', 12);
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Legacy code extraction
// -------------------------------------------------------------------------

describe('legacy code extraction', function () {
    it('extracts non-Configure code to app/Includes/Legacy.php', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, []);
        writeLegacyConfig(
            $tmpDir,
            "Configure::write('urls.relative', true);\ndefine('WP_POST_REVISIONS', 5);\nupdate_option('foo', 'bar');"
        );

        (new MigrateConfigs($command, $modernizer))();

        $content = file_get_contents($tmpDir . '/app/Includes/Legacy.php');

        expect($tmpDir . '/app/Includes/Legacy.php')->toBeFile();
        expect($content)
            ->toContain("define('WP_POST_REVISIONS', 5)")
            ->toContain("update_option('foo', 'bar')")
            ->not->toContain('Configure::write');

        removeTmpDir($tmpDir);
    });

    it('does not create Legacy.php when no legacy code exists', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['urls' => ['relative' => true]]);
        writeLegacyConfig($tmpDir, "Configure::write('urls.relative', true);");

        (new MigrateConfigs($command, $modernizer))();

        expect($tmpDir . '/app/Includes/Legacy.php')->not->toBeFile();
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// File backup
// -------------------------------------------------------------------------

describe('file backup', function () {
    it('moves processed config files to _climb_backup/', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, []);
        writeLegacyConfig($tmpDir, "Configure::write('urls.relative', true);");

        (new MigrateConfigs($command, $modernizer))();

        expect($tmpDir . '/app/config/app.config.php')->not->toBeFile();
        expect($tmpDir . '/_climb_backup/app/config/app.config.php')->toBeFile();
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Configure::read rewriting
// -------------------------------------------------------------------------

describe('configure read rewriting', function () {
    it('rewrites known Configure::read calls in app code', function () {
        [$tmpDir, $command, $modernizer] = migrateConfigsSetup();
        writeConfigDump($tmpDir, ['urls' => ['relative' => true]]);
        writeLegacyConfig($tmpDir, "Configure::write('urls.relative', true);");
        file_put_contents(
            $tmpDir . '/app/SomeClass.php',
            "<?php\n\$val = Configure::read('urls.relative');"
        );

        $report = (new MigrateConfigs($command, $modernizer))();

        expect($report['migrated'])->toContainWith(
            fn ($m) => str_contains($m, "Configure::read('urls.relative')")
        );
        removeTmpDir($tmpDir);
    });
});
