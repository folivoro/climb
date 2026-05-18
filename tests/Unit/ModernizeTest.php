<?php

declare(strict_types=1);

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function commandSetup(): string
{
    $tmpDir = sys_get_temp_dir() . '/climb-test-' . uniqid();
    mkdir($tmpDir . '/vendor/composer', 0755, true);
    chdir($tmpDir);

    return $tmpDir;
}

function writeComposerJson(string $tmpDir): void
{
    file_put_contents($tmpDir . '/composer.json', json_encode(['name' => 'test/project']));
}

function writeInstalledPhp(string $tmpDir, string $package, string $version): void
{
    $data = "<?php return ['versions' => ['{$package}' => ['version' => '{$version}']]];\n";
    file_put_contents($tmpDir . '/vendor/composer/installed.php', $data);
}

function writeComposerLock(string $tmpDir, string $package, string $version): void
{
    file_put_contents($tmpDir . '/composer.lock', json_encode([
        'packages'     => [['name' => $package, 'version' => $version]],
        'packages-dev' => [],
    ]));
}

function mockV2Modernizer(): void
{
    app()->mock(
        \App\Modernizers\V2\Modernizer::class,
        fn ($mock) => $mock
            ->shouldReceive('setup')->once()
            ->shouldReceive('run')->once()
            ->shouldReceive('report')->once()
    );
}

afterEach(function () {
    Mockery::close();
    chdir(sys_get_temp_dir());
});

// -------------------------------------------------------------------------
// Prerequisites
// -------------------------------------------------------------------------

describe('prerequisites', function () {
    it('fails when composer.json is missing', function () {
        $tmpDir = commandSetup();

        $this->artisan('modernize')->expectsOutputToContain('composer.json')->assertFailed();

        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Version detection — installed.php
// -------------------------------------------------------------------------

describe('version detection via installed.php', function () {
    it('detects sixmonkey/sloth as v1', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'sixmonkey/sloth', '1.2.3');
        mockV2Modernizer();

        $this->artisan('modernize')->expectsOutputToContain('v1 → v2')->assertSuccessful();
        removeTmpDir($tmpDir);
    });

    it('detects folivoro/sloth 1.x as v1', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'folivoro/sloth', '1.0.2');
        mockV2Modernizer();

        $this->artisan('modernize')->expectsOutputToContain('v1 → v2')->assertSuccessful();
        removeTmpDir($tmpDir);
    });

    it('treats dev-main as v1', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'folivoro/sloth', 'dev-main');
        mockV2Modernizer();

        $this->artisan('modernize')->expectsOutputToContain('v1 → v2')->assertSuccessful();
        removeTmpDir($tmpDir);
    });

    it('treats dev-feat/my-feature as v1', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'folivoro/sloth', 'dev-feat/my-feature');
        mockV2Modernizer();

        $this->artisan('modernize')->expectsOutputToContain('v1 → v2')->assertSuccessful();
        removeTmpDir($tmpDir);
    });

    it('treats 9999999-dev as v1', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'folivoro/sloth', '9999999-dev');
        mockV2Modernizer();

        $this->artisan('modernize')->expectsOutputToContain('v1 → v2')->assertSuccessful();
        removeTmpDir($tmpDir);
    });

    it('fails when no Sloth package is found', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        file_put_contents(
            $tmpDir . '/vendor/composer/installed.php',
            "<?php return ['versions' => ['some/other' => ['version' => '1.0.0']]];\n"
        );

        $this->artisan('modernize')->expectsOutputToContain('No Sloth package found')->assertFailed();
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Version detection — composer.lock fallback
// -------------------------------------------------------------------------

describe('version detection fallback via composer.lock', function () {
    it('falls back to composer.lock when vendor/ is absent', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        rmdir($tmpDir . '/vendor/composer');
        rmdir($tmpDir . '/vendor');
        writeComposerLock($tmpDir, 'sixmonkey/sloth', '1.0.0');
        mockV2Modernizer();

        $this->artisan('modernize')->expectsOutputToContain('v1 → v2')->assertSuccessful();
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// --to flag
// -------------------------------------------------------------------------

describe('--to flag', function () {
    it('defaults to highest available TO version', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'sixmonkey/sloth', '1.0.0');
        mockV2Modernizer();

        $this->artisan('modernize')->expectsOutputToContain('v1 → v2')->assertSuccessful();
        removeTmpDir($tmpDir);
    });

    it('accepts explicit --to flag', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'sixmonkey/sloth', '1.0.0');
        mockV2Modernizer();

        $this->artisan('modernize', ['--to' => 2])->assertSuccessful();
        removeTmpDir($tmpDir);
    });

    it('fails when no modernizer exists for the requested --to', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'sixmonkey/sloth', '1.0.0');

        $this->artisan('modernize', ['--to' => 99])
            ->expectsOutputToContain('No modernizer available')
            ->assertFailed();

        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Downgrade warning
// -------------------------------------------------------------------------

describe('downgrade warning', function () {
    it('warns and aborts on no when installed >= target', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'folivoro/sloth', '2.0.0');

        $this->artisan('modernize', ['--to' => 2])
            ->expectsOutputToContain('downgrade')
            ->expectsConfirmation('Are you sure you want to continue?', 'no')
            ->assertFailed();

        removeTmpDir($tmpDir);
    });

    it('proceeds when user confirms downgrade', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'folivoro/sloth', '2.0.0');
        mockV2Modernizer();

        $this->artisan('modernize', ['--to' => 2])
            ->expectsConfirmation('Are you sure you want to continue?', 'yes')
            ->assertSuccessful();

        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Happy path
// -------------------------------------------------------------------------

describe('happy path', function () {
    it('calls setup, run and report in order', function () {
        $tmpDir = commandSetup();
        writeComposerJson($tmpDir);
        writeInstalledPhp($tmpDir, 'sixmonkey/sloth', '1.0.0');

        app()->mock(
            \App\Modernizers\V2\Modernizer::class,
            fn ($mock) => $mock
                ->shouldReceive('setup')->once()->ordered()
                ->shouldReceive('run')->once()->ordered()
                ->shouldReceive('report')->once()->ordered()
        );

        $this->artisan('modernize')->assertSuccessful();
        removeTmpDir($tmpDir);
    });
});
