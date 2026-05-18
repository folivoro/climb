<?php

declare(strict_types=1);

namespace App\Modernizers;

use App\Modernizers\AbstractModernizer;
use Symfony\Component\Console\Command\Command;

/**
 * Base class for all modernizer jobs.
 *
 * Each job encapsulates a single migration step and is invoked
 * by its parent Modernizer via __invoke(). Jobs receive the console
 * command for I/O and the modernizer instance for shared context
 * (appDir, themeDir etc.).
 *
 * ## Report format
 *
 * Each job returns a structured report array after execution:
 *
 * ```php
 * [
 *     'migrated' => ['layotter.custom_classes → layotter.custom_classes'],
 *     'dropped'  => ['plugins.autoactivate'],
 *     'manual'   => ['Configure::read at app/Http/Controller.php:42'],
 * ]
 * ```
 *
 * The Modernizer collects all reports and prints a consolidated
 * summary at the end of the modernization process.
 *
 * ## I/O
 *
 * Use $this->command for all console output and prompts. Never
 * write directly to stdout/stderr.
 *
 * @since 1.0.0
 */
abstract class AbstractJob
{
    /**
     * Collected report lines, keyed by category.
     *
     * @var array<string, list<string>>
     */
    protected array $report = [
        'migrated' => [],
        'dropped'  => [],
        'manual'   => [],
    ];

    /**
     * @param Command            $command    Console command for I/O and prompts.
     * @param AbstractModernizer $modernizer Parent modernizer carrying shared paths.
     */
    public function __construct(
        protected Command $command,
        protected AbstractModernizer $modernizer,
    ) {}

    /**
     * Execute the job.
     *
     * Implementations should perform their migration logic here and
     * populate $this->report before returning it.
     *
     * @return array<string, list<string>> Structured report for this job.
     */
    abstract public function __invoke(): array;

    /**
     * Record a successfully migrated item.
     *
     * @param string $message Human-readable description of what was migrated.
     */
    protected function migrated(string $message): void
    {
        $this->report['migrated'][] = $message;
    }

    /**
     * Record a dropped item.
     *
     * @param string $message Human-readable description of what was dropped.
     */
    protected function dropped(string $message): void
    {
        $this->report['dropped'][] = $message;
    }

    /**
     * Record an item that requires manual intervention.
     *
     * @param string $message Human-readable description including file and line where possible.
     */
    protected function manual(string $message): void
    {
        $this->report['manual'][] = $message;
    }

    /**
     * Return the collected report for this job.
     *
     * @return array<string, list<string>>
     */
    public function getReport(): array
    {
        return $this->report;
    }
}
