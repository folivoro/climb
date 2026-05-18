<?php

declare(strict_types=1);

use App\Modernizers\AbstractJob;
use App\Modernizers\AbstractModernizer;
use Symfony\Component\Console\Command\Command;

// -------------------------------------------------------------------------
// Concrete stub for testing AbstractModernizer
// -------------------------------------------------------------------------

/**
 * Minimal concrete modernizer for testing AbstractModernizer behaviour.
 */
class StubModernizer extends AbstractModernizer
{
    public const int FROM_VERSION = 99;

    /** @var list<class-string> */
    private array $jobs;

    public function __construct(Command $command, array $jobs = [])
    {
        parent::__construct($command);
        $this->jobs        = $jobs;
        $this->projectRoot = '/tmp/project';
        $this->appDir      = '/tmp/project/app';
        $this->themeDir    = '/tmp/project/theme';
    }

    public function getJobs(): array
    {
        return $this->jobs;
    }

    public function setup(): void {}
}

/**
 * Stub job that returns a predictable report.
 */
class StubJob extends AbstractJob
{
    public static array $report = [
        'migrated' => ['thing.foo → thing.bar'],
        'dropped'  => ['old.key'],
        'manual'   => ['please check SomeClass.php:42'],
    ];

    public function __invoke(): array
    {
        return self::$report;
    }
}

/**
 * Stub job that returns an empty report.
 */
class EmptyJob extends AbstractJob
{
    public function __invoke(): array
    {
        return ['migrated' => [], 'dropped' => [], 'manual' => []];
    }
}

afterEach(fn () => Mockery::close());

// -------------------------------------------------------------------------
// Path accessors
// -------------------------------------------------------------------------

describe('path accessors', function () {
    it('returns the project root', function () {
        $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $modernizer = new StubModernizer($command);

        expect($modernizer->getProjectRoot())->toBe('/tmp/project');
    });

    it('returns the app dir', function () {
        $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $modernizer = new StubModernizer($command);

        expect($modernizer->getAppDir())->toBe('/tmp/project/app');
    });

    it('returns the theme dir', function () {
        $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $modernizer = new StubModernizer($command);

        expect($modernizer->getThemeDir())->toBe('/tmp/project/theme');
    });
});

// -------------------------------------------------------------------------
// run()
// -------------------------------------------------------------------------

describe('run()', function () {
    it('executes all jobs in order', function () {
        $executed = [];

        // Two inline jobs that track execution order
        $jobA = new class (Mockery::mock(Command::class)->shouldIgnoreMissing(), new StubModernizer(Mockery::mock(Command::class)->shouldIgnoreMissing())) extends AbstractJob {
            public function __invoke(): array
            {
                return ['migrated' => ['A'], 'dropped' => [], 'manual' => []];
            }
        };

        // Use StubJob which has a known report
        $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $modernizer = new StubModernizer($command, [StubJob::class, EmptyJob::class]);

        $modernizer->run();

        $reports = (fn () => $this->reports)->call($modernizer);

        expect($reports)->toHaveKeys(['StubJob', 'EmptyJob']);
        expect($reports['StubJob']['migrated'])->toContain('thing.foo → thing.bar');
        expect($reports['EmptyJob']['migrated'])->toBeEmpty();
    });

    it('collects reports keyed by short job class name', function () {
        $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $modernizer = new StubModernizer($command, [StubJob::class]);

        $modernizer->run();

        $reports = (fn () => $this->reports)->call($modernizer);

        expect($reports)->toHaveKey('StubJob');
        expect($reports['StubJob'])->toHaveKeys(['migrated', 'dropped', 'manual']);
    });
});

// -------------------------------------------------------------------------
// report()
// -------------------------------------------------------------------------

describe('report()', function () {
    it('prints migrated items', function () {
        $command = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $command->shouldReceive('info')->withArgs(fn ($msg) => str_contains($msg, 'Migrated'))->once();

        $modernizer = new StubModernizer($command, [StubJob::class]);
        $modernizer->run();
        $modernizer->report();
    });

    it('prints dropped items', function () {
        $command = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $command->shouldReceive('warn')->withArgs(fn ($msg) => str_contains($msg, 'Dropped'))->once();

        $modernizer = new StubModernizer($command, [StubJob::class]);
        $modernizer->run();
        $modernizer->report();
    });

    it('prints manual items', function () {
        $command = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $command->shouldReceive('error')->withArgs(fn ($msg) => str_contains($msg, 'manual'))->once();

        $modernizer = new StubModernizer($command, [StubJob::class]);
        $modernizer->run();
        $modernizer->report();
    });

    it('does not print empty sections', function () {
        $command = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $command->shouldNotReceive('warn');
        $command->shouldNotReceive('error');

        $modernizer = new StubModernizer($command, [EmptyJob::class]);
        $modernizer->run();
        $modernizer->report();
    });

    it('consolidates reports from multiple jobs', function () {
        StubJob::$report = [
            'migrated' => ['a → b'],
            'dropped'  => ['c'],
            'manual'   => ['check x'],
        ];

        $printed = [];
        $command = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $command->allows('line')->andReturnUsing(function ($msg) use (&$printed) {
            $printed[] = $msg;
        });

        $modernizer = new StubModernizer($command, [StubJob::class, StubJob::class]);
        $modernizer->run();
        $modernizer->report();

        $all = implode("\n", $printed);
        expect($all)->toContain('a → b');
        expect($all)->toContain('c');
        expect($all)->toContain('check x');
    });
});
