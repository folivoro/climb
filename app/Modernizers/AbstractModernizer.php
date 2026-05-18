<?php

declare(strict_types=1);

namespace App\Modernizers;

use Symfony\Component\Console\Command\Command;

/**
 * Base class for all version modernizers.
 *
 * A Modernizer orchestrates a sequence of Jobs that together migrate a
 * project from one Sloth version to the next. Each concrete Modernizer
 * declares FROM_VERSION and TO_VERSION, and the Modernize command selects
 * the correct one based on the installed version and the desired target.
 *
 * ## Lifecycle
 *
 * 1. `Modernize` command resolves the correct Modernizer via FROM/TO version.
 * 2. `setup()` is called — discovers project paths interactively if needed.
 * 3. `run()` is called — executes each job in order, collecting reports.
 * 4. `report()` is called — prints a consolidated summary to the console.
 *
 * ## Adding a new version
 *
 * Create `App\Modernizers\V3\Modernizer` with `FROM_VERSION = 2, TO_VERSION = 3`,
 * register it in `Modernize::MODERNIZERS`, and the command picks it up automatically.
 *
 * @since 1.0.0
 */
abstract class AbstractModernizer
{
    /**
     * The Sloth major version this modernizer migrates FROM.
     */
    public const int FROM_VERSION = 0;

    /**
     * The Sloth major version this modernizer migrates TO.
     */
    public const int TO_VERSION = 0;

    /**
     * Absolute path to the project root (where composer.json lives).
     */
    protected string $projectRoot;

    /**
     * Absolute path to the app directory.
     */
    protected string $appDir;

    /**
     * Absolute path to the active theme directory.
     */
    protected string $themeDir;

    /**
     * Absolute path to the WordPress MU-plugins directory.
     * Null when not detectable from installer-paths.
     */
    protected ?string $muPluginDir = null;

    /**
     * Collected reports from all executed jobs, keyed by job class name.
     *
     * @var array<string, array<string, list<string>>>
     */
    protected array $reports = [];

    /**
     * @param Command $command Console command for I/O and prompts.
     */
    public function __construct(protected Command $command) {}

    /**
     * Return the ordered list of job class names to execute.
     *
     * @return list<class-string<AbstractJob>>
     */
    abstract public function getJobs(): array;

    /**
     * Discover and validate project paths interactively.
     */
    abstract public function setup(): void;

    /**
     * Execute all jobs in order and collect their reports.
     */
    public function run(): void
    {
        foreach ($this->getJobs() as $jobClass) {
            $job                       = new $jobClass($this->command, $this);
            $shortName                 = (new \ReflectionClass($jobClass))->getShortName();
            $this->reports[$shortName] = $job();
        }
    }

    /**
     * Print a consolidated report of all job results to the console.
     */
    public function report(): void
    {
        $migrated = [];
        $dropped  = [];
        $manual   = [];

        foreach ($this->reports as $report) {
            $migrated = array_merge($migrated, $report['migrated'] ?? []);
            $dropped  = array_merge($dropped, $report['dropped'] ?? []);
            $manual   = array_merge($manual, $report['manual'] ?? []);
        }

        $this->command->line('');
        $this->command->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->line('  climb — migration report');
        $this->command->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (!empty($migrated)) {
            $this->command->line('');
            $this->command->info('  ✅ Migrated (' . count($migrated) . ')');
            foreach ($migrated as $line) {
                $this->command->line("     {$line}");
            }
        }

        if (!empty($dropped)) {
            $this->command->line('');
            $this->command->warn('  🗑  Dropped (' . count($dropped) . ')');
            foreach ($dropped as $line) {
                $this->command->line("     {$line}");
            }
        }

        if (!empty($manual)) {
            $this->command->line('');
            $this->command->error('  🔧 Requires manual attention (' . count($manual) . ')');
            foreach ($manual as $line) {
                $this->command->line("     {$line}");
            }
            $this->command->line('');
            $this->command->line('  See https://folivoro.com/docs/upgrade/ for guidance.');
        }

        $this->command->line('');
        $this->command->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    public function getProjectRoot(): string { return $this->projectRoot; }
    public function getAppDir(): string      { return $this->appDir; }
    public function getThemeDir(): string      { return $this->themeDir; }
    public function getMuPluginDir(): ?string   { return $this->muPluginDir; }
}
