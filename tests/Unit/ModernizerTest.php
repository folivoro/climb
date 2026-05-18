<?php

declare(strict_types=1);

use App\Modernizers\V2\Modernizer;
use Symfony\Component\Console\Command\Command;

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function modernizerSetup(): array
{
    $tmpDir = sys_get_temp_dir() . '/climb-test-' . uniqid();
    mkdir($tmpDir . '/app', 0755, true);

    $command = Mockery::mock(Command::class)->shouldIgnoreMissing();

    return [$tmpDir, $command];
}

function makeModernizer(Command $command, string $tmpDir): Modernizer
{
    $modernizer = new class ($command) extends Modernizer {
        public string $injectedRoot;

        public function setup(): void
        {
            $this->projectRoot = $this->injectedRoot;
            parent::setup();
        }
    };

    $modernizer->injectedRoot = $tmpDir;

    return $modernizer;
}

afterEach(fn () => Mockery::close());

// -------------------------------------------------------------------------
// FROM_VERSION
// -------------------------------------------------------------------------

describe('FROM_VERSION', function () {
    it('is 1', function () {
        expect(Modernizer::FROM_VERSION)->toBe(1);
    });
});

// -------------------------------------------------------------------------
// getJobs()
// -------------------------------------------------------------------------

describe('getJobs()', function () {
    it('returns all five expected job classes in order', function () {
        $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
        $modernizer = new Modernizer($command);

        expect($modernizer->getJobs())->toBe([
            \App\Modernizers\V2\Jobs\UpdateComposerPackages::class,
            \App\Modernizers\V2\Jobs\MigrateConfigs::class,
            \App\Modernizers\V2\Jobs\MigrateViewExtensions::class,
            \App\Modernizers\V2\Jobs\MigrateTypedProperties::class,
            \App\Modernizers\V2\Jobs\MigrateBootstrap::class,
        ]);
    });
});

// -------------------------------------------------------------------------
// setup() — app dir
// -------------------------------------------------------------------------

describe('setup() — app dir', function () {
    it('accepts a valid app directory', function () {
        [$tmpDir, $command] = modernizerSetup();

        // Provide valid app dir and a theme
        $themeDir = $tmpDir . '/themes/mytheme';
        mkdir($themeDir, 0755, true);
        file_put_contents($themeDir . '/style.css', '');

        \Laravel\Prompts\Prompt::fake([
            $tmpDir . '/app',       // app dir prompt
            $themeDir,              // themes dir (no composer.json)
        ]);

        $modernizer = new Modernizer($command);

        // Expose projectRoot for setup
        (fn () => $this->projectRoot = $tmpDir)->call($modernizer);
        $modernizer->setup();

        expect($modernizer->getAppDir())->toBe(realpath($tmpDir . '/app'));
        removeTmpDir($tmpDir);
    });

    it('re-prompts when app directory does not exist', function () {
        [$tmpDir, $command] = modernizerSetup();
        $command->shouldReceive('error')->once();

        $themeDir = $tmpDir . '/themes/mytheme';
        mkdir($themeDir, 0755, true);
        file_put_contents($themeDir . '/style.css', '');

        \Laravel\Prompts\Prompt::fake([
            '/does/not/exist',      // first attempt — invalid
            $tmpDir . '/app',       // second attempt — valid
            $themeDir,
        ]);

        $modernizer = new Modernizer($command);
        (fn () => $this->projectRoot = $tmpDir)->call($modernizer);
        $modernizer->setup();

        expect($modernizer->getAppDir())->toBe(realpath($tmpDir . '/app'));
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// setup() — theme dir
// -------------------------------------------------------------------------

describe('setup() — theme dir', function () {
    it('selects the only theme automatically when one is found', function () {
        [$tmpDir, $command] = modernizerSetup();

        $themeDir = $tmpDir . '/themes/mytheme';
        mkdir($themeDir, 0755, true);
        file_put_contents($themeDir . '/style.css', '');

        // Write a composer.json with installer-paths pointing to themes
        file_put_contents($tmpDir . '/composer.json', json_encode([
            'extra' => [
                'installer-paths' => [
                    $tmpDir . '/themes/{$name}' => ['type:wordpress-theme'],
                ],
            ],
        ]));

        \Laravel\Prompts\Prompt::fake([$tmpDir . '/app']);

        $modernizer = new Modernizer($command);
        (fn () => $this->projectRoot = $tmpDir)->call($modernizer);
        $modernizer->setup();

        expect($modernizer->getThemeDir())->toBe(realpath($themeDir));
        removeTmpDir($tmpDir);
    });

    it('presents a select when multiple themes are found', function () {
        [$tmpDir, $command] = modernizerSetup();

        $themeA = $tmpDir . '/themes/theme-a';
        $themeB = $tmpDir . '/themes/theme-b';
        mkdir($themeA, 0755, true);
        mkdir($themeB, 0755, true);
        file_put_contents($themeA . '/style.css', '');
        file_put_contents($themeB . '/style.css', '');

        file_put_contents($tmpDir . '/composer.json', json_encode([
            'extra' => [
                'installer-paths' => [
                    $tmpDir . '/themes/{$name}' => ['type:wordpress-theme'],
                ],
            ],
        ]));

        // app dir prompt, then select theme-b
        \Laravel\Prompts\Prompt::fake([$tmpDir . '/app', $themeB]);

        $modernizer = new Modernizer($command);
        (fn () => $this->projectRoot = $tmpDir)->call($modernizer);
        $modernizer->setup();

        expect($modernizer->getThemeDir())->toBe(realpath($themeB));
        removeTmpDir($tmpDir);
    });

    it('ignores directories without style.css', function () {
        [$tmpDir, $command] = modernizerSetup();

        $validTheme   = $tmpDir . '/themes/valid';
        $invalidTheme = $tmpDir . '/themes/not-a-theme';
        mkdir($validTheme, 0755, true);
        mkdir($invalidTheme, 0755, true);
        file_put_contents($validTheme . '/style.css', '');
        // No style.css in invalidTheme

        file_put_contents($tmpDir . '/composer.json', json_encode([
            'extra' => [
                'installer-paths' => [
                    $tmpDir . '/themes/{$name}' => ['type:wordpress-theme'],
                ],
            ],
        ]));

        \Laravel\Prompts\Prompt::fake([$tmpDir . '/app']);

        $modernizer = new Modernizer($command);
        (fn () => $this->projectRoot = $tmpDir)->call($modernizer);
        $modernizer->setup();

        expect($modernizer->getThemeDir())->toBe(realpath($validTheme));
        removeTmpDir($tmpDir);
    });

    it('falls back to asking for theme dir when composer.json has no installer-paths', function () {
        [$tmpDir, $command] = modernizerSetup();

        $themeDir = $tmpDir . '/themes/mytheme';
        mkdir($themeDir, 0755, true);
        file_put_contents($themeDir . '/style.css', '');

        file_put_contents($tmpDir . '/composer.json', json_encode([]));

        \Laravel\Prompts\Prompt::fake([
            $tmpDir . '/app',           // app dir
            $tmpDir . '/themes',        // themes dir (fallback prompt)
        ]);

        $modernizer = new Modernizer($command);
        (fn () => $this->projectRoot = $tmpDir)->call($modernizer);
        $modernizer->setup();

        expect($modernizer->getThemeDir())->toBe(realpath($themeDir));
        removeTmpDir($tmpDir);
    });
});
