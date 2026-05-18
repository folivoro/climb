<?php

declare(strict_types=1);

use App\Modernizers\AbstractModernizer;
use App\Modernizers\V2\Jobs\MigrateBootstrap;
use Symfony\Component\Console\Command\Command;

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function bootstrapSetup(): array
{
    $tmpDir = sys_get_temp_dir() . '/climb-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
    $modernizer = Mockery::mock(AbstractModernizer::class);
    $modernizer->allows('getProjectRoot')->andReturn($tmpDir);
    $modernizer->allows('getAppDir')->andReturn($tmpDir . '/app');
    $modernizer->allows('getThemeDir')->andReturn($tmpDir . '/theme');

    return [$tmpDir, $command, $modernizer];
}

function writeBootstrap(string $tmpDir, string $body): void
{
    file_put_contents($tmpDir . '/bootstrap.php', "<?php\n\n{$body}\n");
}

function bootstrapContent(string $tmpDir): string
{
    return file_get_contents($tmpDir . '/bootstrap.php');
}

afterEach(fn () => Mockery::close());

// -------------------------------------------------------------------------
// Configure alias + boot removal
// -------------------------------------------------------------------------

describe('Configure alias removal', function () {
    it('removes the Configure class_alias call', function () {
        [$tmpDir, $command, $modernizer] = bootstrapSetup();
        writeBootstrap($tmpDir, <<<'PHP'
            class_alias('\Sloth\Configure\Configure', 'Configure');
            Configure::boot();
            Core\Sloth::getInstance()->boot();
            PHP);

        (new MigrateBootstrap($command, $modernizer))();

        expect(bootstrapContent($tmpDir))
            ->not->toContain('class_alias')
            ->not->toContain('Configure::boot');

        removeTmpDir($tmpDir);
    });

    it('replaces Sloth singleton boot with Application::configure()->boot()', function () {
        [$tmpDir, $command, $modernizer] = bootstrapSetup();
        writeBootstrap($tmpDir, "Core\\Sloth::getInstance()->boot();");

        (new MigrateBootstrap($command, $modernizer))();

        expect(bootstrapContent($tmpDir))
            ->toContain('Application::configure(basePath: __DIR__)->boot()')
            ->not->toContain('Core\Sloth::getInstance()');

        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Application import
// -------------------------------------------------------------------------

describe('Application use statement', function () {
    it('adds use statement for Application when missing', function () {
        [$tmpDir, $command, $modernizer] = bootstrapSetup();
        writeBootstrap($tmpDir, "Core\\Sloth::getInstance()->boot();");

        (new MigrateBootstrap($command, $modernizer))();

        expect(bootstrapContent($tmpDir))->toContain('use Sloth\Core\Application;');
        removeTmpDir($tmpDir);
    });

    it('does not duplicate use statement when already present', function () {
        [$tmpDir, $command, $modernizer] = bootstrapSetup();
        writeBootstrap($tmpDir, <<<'PHP'
            use Sloth\Core\Application;
            Core\Sloth::getInstance()->boot();
            PHP);

        (new MigrateBootstrap($command, $modernizer))();

        expect(substr_count(bootstrapContent($tmpDir), 'use Sloth\Core\Application'))->toBe(1);
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Loader include removal
// -------------------------------------------------------------------------

describe('loader include removal', function () {
    it('removes the old loader.php include block', function () {
        [$tmpDir, $command, $modernizer] = bootstrapSetup();
        writeBootstrap($tmpDir, <<<'PHP'
            if (file_exists(DIR_CFG . 'loader.php')) {
                include DIR_CFG . 'loader.php';
            }
            Core\Sloth::getInstance()->boot();
            PHP);

        (new MigrateBootstrap($command, $modernizer))();

        expect(bootstrapContent($tmpDir))->not->toContain('loader.php');
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Missing files
// -------------------------------------------------------------------------

describe('missing files', function () {
    it('records a manual item when bootstrap.php is not found', function () {
        [$tmpDir, $command, $modernizer] = bootstrapSetup();

        $report = (new MigrateBootstrap($command, $modernizer))();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, 'bootstrap.php not found'));
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Report
// -------------------------------------------------------------------------

describe('report', function () {
    it('records bootstrap as migrated', function () {
        [$tmpDir, $command, $modernizer] = bootstrapSetup();
        writeBootstrap($tmpDir, "Core\\Sloth::getInstance()->boot();");

        $report = (new MigrateBootstrap($command, $modernizer))();

        expect($report['migrated'])->toContain('bootstrap.php updated to Sloth v2 boot syntax');
        removeTmpDir($tmpDir);
    });

    it('records a git tracking reminder in manual', function () {
        [$tmpDir, $command, $modernizer] = bootstrapSetup();
        writeBootstrap($tmpDir, "Core\\Sloth::getInstance()->boot();");
        file_put_contents($tmpDir . '/wp-config.php', '<?php');

        $report = (new MigrateBootstrap($command, $modernizer))();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, '.gitignore'));
        removeTmpDir($tmpDir);
    });
});
