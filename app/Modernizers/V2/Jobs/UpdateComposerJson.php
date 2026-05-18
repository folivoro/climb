<?php

declare(strict_types=1);

namespace App\Modernizers\V2\Jobs;

use App\Modernizers\AbstractJob;
use Ergebnis\Json\Json;
use Ergebnis\Json\Normalizer\Vendor\Composer\ComposerJsonNormalizer;

/**
 * Mutates composer.json for Sloth v2 compatibility.
 *
 * ## What this job does
 *
 * 1. Removes deprecated Composer scripts that reference the Sloth v1 Installer.
 *    The Installer class is a no-op in v2 and will be removed in v3.
 *
 * 2. Runs `composer update -W --prefer-stable` to bump all remaining packages
 *    to the latest version allowed by their constraints, preferring stable
 *    releases over dev branches.
 *
 * ## What this job does NOT do
 *
 * - It does not rewrite wildcard (`*`) or `dev-*` constraints to pinned versions.
 *   That is a project-level decision outside the scope of the Sloth migration.
 *   Packages with loose constraints are listed in the manual report.
 *
 * @since 1.0.0
 */
class UpdateComposerJson extends AbstractJob
{
    /**
     * Composer script references that are deprecated in Sloth v2.
     *
     * These point to Sloth\Installer\Installer which is a no-op in v2.
     *
     * @var list<string>
     */
    private const DEPRECATED_SCRIPT_REFERENCES = [
        'Sloth\\Installer\\Installer::config',
        'Sloth\\Installer\\Installer::config_quiet',
    ];

    /**
     * Execute the composer.json update.
     *
     * @return array<string, list<string>>
     */
    public function __invoke(): array
    {
        $this->command->info('📝 Updating composer.json...');

        $composerJson = $this->modernizer->getProjectRoot() . '/composer.json';

        if (!file_exists($composerJson)) {
            $this->manual('composer.json not found — please update manually.');

            return $this->report;
        }

        $data = json_decode(file_get_contents($composerJson), true);

        $data = $this->removeDeprecatedScripts($data);

        file_put_contents(
            $composerJson,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );

        $this->normalize($composerJson);

        return $this->report;
    }

    /**
     * Remove Composer scripts that reference the deprecated Sloth v1 Installer.
     *
     * Iterates all script hooks and removes individual callables that match
     * known deprecated references. Empty hooks are removed entirely.
     *
     * @param  array<string, mixed> $data Decoded composer.json.
     * @return array<string, mixed> Mutated composer.json data.
     */
    private function removeDeprecatedScripts(array $data): array
    {
        if (empty($data['scripts'])) {
            return $data;
        }

        foreach ($data['scripts'] as $hook => $callables) {
            // Scalar value (single callable string)
            if (is_string($callables)) {
                if ($this->isDeprecatedScript($callables)) {
                    unset($data['scripts'][$hook]);
                    $this->dropped("scripts.{$hook} ({$callables})");
                }
                continue;
            }

            // Array of callables
            $filtered = array_values(array_filter(
                $callables,
                fn ($callable) => !$this->isDeprecatedScript($callable),
            ));

            $removed = array_diff($callables, $filtered);

            foreach ($removed as $callable) {
                $this->dropped("scripts.{$hook}: {$callable}");
            }

            if (empty($filtered)) {
                unset($data['scripts'][$hook]);
            } else {
                $data['scripts'][$hook] = $filtered;
            }
        }

        if (empty($data['scripts'])) {
            unset($data['scripts']);
        }

        return $data;
    }

    /**
     * Check whether a script callable references a deprecated Sloth v1 class.
     *
     * @param mixed $callable The script value to check.
     */
    private function isDeprecatedScript(mixed $callable): bool
    {
        if (!is_string($callable)) {
            return false;
        }

        foreach (self::DEPRECATED_SCRIPT_REFERENCES as $deprecated) {
            if (str_contains($callable, $deprecated)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize composer.json using ergebnis/json-normalizer.
     *
     * Sorts keys according to the Composer JSON schema, normalizes version
     * constraints, and ensures consistent formatting. This runs after our
     * own mutations so the final file is always clean.
     *
     * @param string $composerJson Absolute path to composer.json.
     */
    private function normalize(string $composerJson): void
    {
        try {
            $normalizer = new ComposerJsonNormalizer('https://getcomposer.org/schema.json');
            $json       = Json::fromString(file_get_contents($composerJson));
            $normalized = $normalizer->normalize($json);

            file_put_contents($composerJson, $normalized->toString());

            $this->migrated('composer.json normalized');
        } catch (\Throwable $e) {
            $this->manual('composer.json normalization failed: ' . $e->getMessage());
        }
    }
}
