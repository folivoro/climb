<?php

declare(strict_types=1);

use App\Modernizers\AbstractModernizer;
use App\Modernizers\V2\Jobs\UpdateComposerJson;
use Symfony\Component\Console\Command\Command;

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function composerJsonSetup(array $data): array
{
    $tmpDir = sys_get_temp_dir() . '/climb-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    file_put_contents($tmpDir . '/composer.json', json_encode($data, JSON_PRETTY_PRINT));

    $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
    $modernizer = Mockery::mock(AbstractModernizer::class);
    $modernizer->allows('getProjectRoot')->andReturn($tmpDir);

    return [$tmpDir, $command, $modernizer];
}

function makeUpdateJob(Command $command, AbstractModernizer $modernizer): UpdateComposerJson
{
    // Stub normalize() so tests don't need a network connection or the full
    // ergebnis/json-normalizer dependency to be resolvable in isolation.
    return new class ($command, $modernizer) extends UpdateComposerJson {
        protected function normalize(string $composerJson): void
        {
            $this->migrated('composer.json normalized');
        }
    };
}

function readComposerJson(string $tmpDir): array
{
    return json_decode(file_get_contents($tmpDir . '/composer.json'), true);
}

afterEach(fn () => Mockery::close());

// -------------------------------------------------------------------------
// Deprecated scripts removal
// -------------------------------------------------------------------------

describe('deprecated scripts removal', function () {
    it('removes post-create-project-cmd referencing Installer::config', function () {
        [$tmpDir, $command, $modernizer] = composerJsonSetup([
            'scripts' => [
                'post-create-project-cmd' => 'Sloth\\Installer\\Installer::config',
            ],
        ]);

        $report = makeUpdateJob($command, $modernizer)();

        expect(readComposerJson($tmpDir))->not->toHaveKey('scripts');
        expect($report['dropped'])->toContainWith(fn ($m) => str_contains($m, 'post-create-project-cmd'));
        removeTmpDir($tmpDir);
    });

    it('removes create-sloth referencing Installer::config_quiet', function () {
        [$tmpDir, $command, $modernizer] = composerJsonSetup([
            'scripts' => [
                'create-sloth' => 'Sloth\\Installer\\Installer::config_quiet',
            ],
        ]);

        makeUpdateJob($command, $modernizer)();

        expect(readComposerJson($tmpDir))->not->toHaveKey('scripts');
        removeTmpDir($tmpDir);
    });

    it('removes only deprecated entries from hooks with multiple callables', function () {
        [$tmpDir, $command, $modernizer] = composerJsonSetup([
            'scripts' => [
                'post-install-cmd' => [
                    'Sloth\\Installer\\Installer::config',
                    '@php artisan something',
                ],
            ],
        ]);

        $report = makeUpdateJob($command, $modernizer)();

        expect(readComposerJson($tmpDir)['scripts']['post-install-cmd'])
            ->toBe(['@php artisan something']);
        expect($report['dropped'])->toContainWith(fn ($m) => str_contains($m, 'Installer::config'));
        removeTmpDir($tmpDir);
    });

    it('removes the entire hook when all callables are deprecated', function () {
        [$tmpDir, $command, $modernizer] = composerJsonSetup([
            'scripts' => [
                'post-install-cmd' => [
                    'Sloth\\Installer\\Installer::config',
                    'Sloth\\Installer\\Installer::config_quiet',
                ],
            ],
        ]);

        makeUpdateJob($command, $modernizer)();

        expect(readComposerJson($tmpDir))->not->toHaveKey('scripts');
        removeTmpDir($tmpDir);
    });

    it('removes the scripts key entirely when all hooks are empty', function () {
        [$tmpDir, $command, $modernizer] = composerJsonSetup([
            'scripts' => [
                'post-create-project-cmd' => 'Sloth\\Installer\\Installer::config',
                'create-sloth'            => 'Sloth\\Installer\\Installer::config_quiet',
            ],
        ]);

        makeUpdateJob($command, $modernizer)();

        expect(readComposerJson($tmpDir))->not->toHaveKey('scripts');
        removeTmpDir($tmpDir);
    });

    it('leaves non-deprecated scripts untouched', function () {
        [$tmpDir, $command, $modernizer] = composerJsonSetup([
            'scripts' => [
                'test' => 'vendor/bin/pest',
            ],
        ]);

        makeUpdateJob($command, $modernizer)();

        expect(readComposerJson($tmpDir)['scripts']['test'])->toBe('vendor/bin/pest');
        removeTmpDir($tmpDir);
    });

    it('does nothing when scripts key is absent', function () {
        [$tmpDir, $command, $modernizer] = composerJsonSetup(['name' => 'test/project']);

        $report = makeUpdateJob($command, $modernizer)();

        expect($report['dropped'])->toBeEmpty();
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Normalization
// -------------------------------------------------------------------------

describe('normalization', function () {
    it('records normalization as migrated', function () {
        [$tmpDir, $command, $modernizer] = composerJsonSetup(['name' => 'test/project']);

        $report = makeUpdateJob($command, $modernizer)();

        expect($report['migrated'])->toContainWith(fn ($m) => str_contains($m, 'normalized'));
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Missing composer.json
// -------------------------------------------------------------------------

describe('missing composer.json', function () {
    it('records manual item when composer.json is not found', function () {
        $tmpDir     = sys_get_temp_dir() . '/climb-test-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $modernizer = Mockery::mock(AbstractModernizer::class);
        $modernizer->allows('getProjectRoot')->andReturn($tmpDir);

        $report = (new UpdateComposerJson($command, $modernizer))();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, 'composer.json not found'));
        removeTmpDir($tmpDir);
    });
});
