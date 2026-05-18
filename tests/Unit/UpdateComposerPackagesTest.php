<?php

declare(strict_types=1);

use App\Modernizers\AbstractModernizer;
use App\Modernizers\V2\Jobs\UpdateComposerPackages;
use Symfony\Component\Console\Command\Command;

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function composerJobSetup(array $require = [], array $requireDev = []): array
{
    $tmpDir = sys_get_temp_dir() . '/climb-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    file_put_contents($tmpDir . '/composer.json', json_encode([
        'name'        => 'test/project',
        'require'     => $require,
        'require-dev' => $requireDev,
    ], JSON_PRETTY_PRINT));

    $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
    $modernizer = Mockery::mock(AbstractModernizer::class);
    $modernizer->allows('getProjectRoot')->andReturn($tmpDir);

    return [$tmpDir, $command, $modernizer];
}

/**
 * Capture composer CLI calls without actually running them.
 * Returns the job with a mocked composer() method.
 */
function makeJob(Command $command, AbstractModernizer $modernizer, array $exitCodes = []): UpdateComposerPackages
{
    $job = new class ($command, $modernizer, $exitCodes) extends UpdateComposerPackages {
        private array $calls     = [];
        private array $exitCodes;
        private int   $callIndex = 0;

        public function __construct(Command $command, AbstractModernizer $modernizer, array $exitCodes)
        {
            parent::__construct($command, $modernizer);
            $this->exitCodes = $exitCodes;
        }

        protected function composer(string $args): int
        {
            $this->calls[] = $args;

            return $this->exitCodes[$this->callIndex++] ?? 0;
        }

        public function getCalls(): array
        {
            return $this->calls;
        }
    };

    return $job;
}

afterEach(fn () => Mockery::close());

// -------------------------------------------------------------------------
// Package detection
// -------------------------------------------------------------------------

describe('package detection', function () {
    it('records manual item when no composer.json exists', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup();
        unlink($tmpDir . '/composer.json');

        $report = (new UpdateComposerPackages($command, $modernizer))();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, 'composer.json not found'));
        removeTmpDir($tmpDir);
    });

    it('records manual item when no known packages are installed', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['some/other' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]); // layotter-bridge prompt

        $job    = makeJob($command, $modernizer);
        $report = $job();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, 'No Sloth or Layotter packages found'));
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Remove packages
// -------------------------------------------------------------------------

describe('removing packages', function () {
    it('removes sixmonkey/sloth', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]);

        $job    = makeJob($command, $modernizer);
        $report = $job();

        expect($job->getCalls()[0])->toContain('remove sixmonkey/sloth');
        expect($report['dropped'])->toContainWith(fn ($m) => str_contains($m, 'sixmonkey/sloth'));
        removeTmpDir($tmpDir);
    });

    it('removes folivoro/sloth regardless of version', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['folivoro/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]);

        $job    = makeJob($command, $modernizer);
        $job();

        expect($job->getCalls()[0])->toContain('remove folivoro/sloth');
        removeTmpDir($tmpDir);
    });

    it('removes hingst/layotter', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup([
            'sixmonkey/sloth' => '^1.0',
            'hingst/layotter' => '^4.0',
        ]);

        \Laravel\Prompts\Prompt::fake([false]);

        $job    = makeJob($command, $modernizer);
        $report = $job();

        expect($job->getCalls()[0])
            ->toContain('sixmonkey/sloth')
            ->toContain('hingst/layotter');

        expect($report['dropped'])->toContainWith(fn ($m) => str_contains($m, 'hingst/layotter'));
        removeTmpDir($tmpDir);
    });

    it('removes folivoro/layotter', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup([
            'folivoro/sloth'   => '^1.0',
            'folivoro/layotter' => '^5.0',
        ]);

        \Laravel\Prompts\Prompt::fake([false]);

        $job    = makeJob($command, $modernizer);
        $report = $job();

        expect($job->getCalls()[0])
            ->toContain('folivoro/sloth')
            ->toContain('folivoro/layotter');

        removeTmpDir($tmpDir);
    });

    it('removes all Sloth and Layotter packages in a single call', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup([
            'sixmonkey/sloth' => '^1.0',
            'hingst/layotter' => '^4.0',
        ]);

        \Laravel\Prompts\Prompt::fake([false]);

        $job = makeJob($command, $modernizer);
        $job();

        // Only one remove call, not two
        $removeCalls = array_filter($job->getCalls(), fn ($c) => str_starts_with($c, 'remove'));
        expect($removeCalls)->toHaveCount(1);
        removeTmpDir($tmpDir);
    });

    it('runs remove with -W flag', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]);

        $job = makeJob($command, $modernizer);
        $job();

        expect($job->getCalls()[0])->toContain('-W');
        removeTmpDir($tmpDir);
    });

    it('records manual item when remove fails', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]);

        $job    = makeJob($command, $modernizer, [1]); // remove fails
        $report = $job();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, 'composer remove'));
        removeTmpDir($tmpDir);
    });

    it('also detects packages from require-dev', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(
            require: [],
            requireDev: ['sixmonkey/sloth' => '^1.0'],
        );

        \Laravel\Prompts\Prompt::fake([false]);

        $job = makeJob($command, $modernizer);
        $job();

        expect($job->getCalls()[0])->toContain('sixmonkey/sloth');
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Require folivoro/sloth:^2.0
// -------------------------------------------------------------------------

describe('requiring folivoro/sloth:^2.0', function () {
    it('runs composer require folivoro/sloth:^2.0 with -W', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]);

        $job = makeJob($command, $modernizer);
        $job();

        $requireCall = collect($job->getCalls())->first(fn ($c) => str_contains($c, 'require folivoro/sloth'));
        expect($requireCall)
            ->toContain('folivoro/sloth:^2.0')
            ->toContain('-W');

        removeTmpDir($tmpDir);
    });

    it('records folivoro/sloth:^2.0 as migrated on success', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]);

        $job    = makeJob($command, $modernizer);
        $report = $job();

        expect($report['migrated'])->toContainWith(fn ($m) => str_contains($m, 'folivoro/sloth:^2.0'));
        removeTmpDir($tmpDir);
    });

    it('records manual item when require fails', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]);

        $job    = makeJob($command, $modernizer, [0, 1]); // remove ok, require fails
        $report = $job();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, 'folivoro/sloth'));
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// folivoro/layotter-bridge
// -------------------------------------------------------------------------

describe('layotter-bridge prompt', function () {
    it('defaults to true when a Layotter package was present', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup([
            'sixmonkey/sloth' => '^1.0',
            'hingst/layotter' => '^4.0',
        ]);

        // Capture the default value passed to confirm()
        $default = null;
        \Laravel\Prompts\Prompt::fake(function ($prompt) use (&$default) {
            $default = $prompt->default;
            return false;
        });

        $job = makeJob($command, $modernizer);
        $job();

        expect($default)->toBeTrue();
        removeTmpDir($tmpDir);
    });

    it('defaults to false when no Layotter package was present', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        $default = null;
        \Laravel\Prompts\Prompt::fake(function ($prompt) use (&$default) {
            $default = $prompt->default;
            return false;
        });

        $job = makeJob($command, $modernizer);
        $job();

        expect($default)->toBeFalse();
        removeTmpDir($tmpDir);
    });

    it('installs bridge with -W when confirmed', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([true]);

        $job    = makeJob($command, $modernizer);
        $report = $job();

        $bridgeCall = collect($job->getCalls())->first(fn ($c) => str_contains($c, 'layotter-bridge'));
        expect($bridgeCall)
            ->toContain('folivoro/layotter-bridge')
            ->toContain('-W');

        expect($report['migrated'])->toContainWith(fn ($m) => str_contains($m, 'layotter-bridge'));
        removeTmpDir($tmpDir);
    });

    it('skips bridge when declined', function () {
        [$tmpDir, $command, $modernizer] = composerJobSetup(['sixmonkey/sloth' => '^1.0']);

        \Laravel\Prompts\Prompt::fake([false]);

        $job = makeJob($command, $modernizer);
        $job();

        $bridgeCall = collect($job->getCalls())->first(fn ($c) => str_contains($c, 'layotter-bridge'));
        expect($bridgeCall)->toBeNull();
        removeTmpDir($tmpDir);
    });
});
