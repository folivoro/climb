<?php

declare(strict_types=1);

use App\Modernizers\AbstractModernizer;
use App\Modernizers\V2\Jobs\MigrateViewExtensions;
use Symfony\Component\Console\Command\Command;

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function viewExtensionsSetup(): array
{
    $tmpDir = sys_get_temp_dir() . '/climb-test-' . uniqid();
    mkdir($tmpDir . '/app/config', 0755, true);
    mkdir($tmpDir . '/theme/Extensions/View', 0755, true);

    $command    = Mockery::mock(Command::class)->shouldIgnoreMissing();
    $modernizer = Mockery::mock(AbstractModernizer::class);
    $modernizer->allows('getProjectRoot')->andReturn($tmpDir);
    $modernizer->allows('getAppDir')->andReturn($tmpDir . '/app');
    $modernizer->allows('getThemeDir')->andReturn($tmpDir . '/theme');

    return [$tmpDir, $command, $modernizer];
}

function writeViewConfig(string $tmpDir, string $body): void
{
    file_put_contents(
        $tmpDir . '/app/config/app.config.php',
        "<?php\n\n{$body}\n"
    );
}

afterEach(fn () => Mockery::close());

// -------------------------------------------------------------------------
// Filter → Helper
// -------------------------------------------------------------------------

describe('filter → Helper class', function () {
    it('omits return type when closure has no explicit return type', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('foo', function ($v) { return $v; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $content = file_get_contents($tmpDir . '/theme/Extensions/View/FooHelper.php');
        expect($content)
            ->toContain('public function __invoke($v)')
            ->not->toContain(': mixed')
            ->not->toContain('): ');

        removeTmpDir($tmpDir);
    });

    it('preserves explicit return type from closure', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('foo', function (string $v): string { return $v; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $content = file_get_contents($tmpDir . '/theme/Extensions/View/FooHelper.php');
        expect($content)->toContain('public function __invoke(string $v): string');

        removeTmpDir($tmpDir);
    });

    it('generates @param docblock from typed closure params', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('foo', function (string $email, array $arguments = []): string {
                    return $email;
                }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $content = file_get_contents($tmpDir . '/theme/Extensions/View/FooHelper.php');
        expect($content)
            ->toContain('@param string $email')
            ->toContain('@param array $arguments');

        removeTmpDir($tmpDir);
    });

    it('generates @param docblock without types for untyped params', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('foo', function ($email, $arguments = []) { return $email; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $content = file_get_contents($tmpDir . '/theme/Extensions/View/FooHelper.php');
        expect($content)
            ->toContain('@param $email')
            ->toContain('@param $arguments');

        removeTmpDir($tmpDir);
    });

    it('generates correct __invoke signature for closures with multiple params', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('obfuscate_email', function ($email, $arguments = []) {
                    return $email;
                }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $content = file_get_contents($tmpDir . '/theme/Extensions/View/ObfuscateEmailHelper.php');
        expect($content)->toContain('public function __invoke($email, $arguments = [])');

        removeTmpDir($tmpDir);
    });

    it('generates a Helper class for a Twig_SimpleFilter', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('obfuscate_email', function ($email) {
                    return htmlspecialchars($email);
                }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $file    = $tmpDir . '/theme/Extensions/View/ObfuscateEmailHelper.php';
        $content = file_get_contents($file);

        expect($file)->toBeFile();
        expect($content)
            ->toContain('class ObfuscateEmailHelper extends AbstractViewExtension')
            ->toContain('public function getHelpers(): array')
            ->toContain("'obfuscate_email' => \$this")
            ->toContain('public function __invoke')
            ->toContain('htmlspecialchars($email)');

        removeTmpDir($tmpDir);
    });

    it('generates separate Helper classes for multiple filters', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('obfuscate_email', function ($email) { return $email; }),
                new Twig_SimpleFilter('format_phone', function ($number) { return $number; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        expect($tmpDir . '/theme/Extensions/View/ObfuscateEmailHelper.php')->toBeFile();
        expect($tmpDir . '/theme/Extensions/View/FormatPhoneHelper.php')->toBeFile();

        removeTmpDir($tmpDir);
    });

    it('handles namespaced Twig\\TwigFilter class', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig\TwigFilter('email_to_json', function ($email) { return []; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        expect($tmpDir . '/theme/Extensions/View/EmailToJsonHelper.php')->toBeFile();
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Function → Directive
// -------------------------------------------------------------------------

describe('function → Directive class', function () {
    it('generates a Directive class for a TwigFunction', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.functions', [
                new TwigFunction('asset', function ($path) {
                    return '/assets/' . $path;
                }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $file    = $tmpDir . '/theme/Extensions/View/AssetDirective.php';
        $content = file_get_contents($file);

        expect($file)->toBeFile();
        expect($content)
            ->toContain('class AssetDirective extends AbstractViewExtension')
            ->toContain('public function getDirectives(): array')
            ->toContain("'asset' => \$this");

        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Class naming
// -------------------------------------------------------------------------

describe('class naming', function () {
    it('converts snake_case filter names to PascalCase', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('my_custom_filter', function ($v) { return $v; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        expect($tmpDir . '/theme/Extensions/View/MyCustomFilterHelper.php')->toBeFile();
        removeTmpDir($tmpDir);
    });

    it('converts hyphenated filter names to PascalCase', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('format-phone', function ($v) { return $v; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        expect($tmpDir . '/theme/Extensions/View/FormatPhoneHelper.php')->toBeFile();
        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Namespace resolution
// -------------------------------------------------------------------------

describe('namespace resolution', function () {
    it('resolves namespace from composer.json psr-4 autoload matching theme dir', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        file_put_contents(
            $tmpDir . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['MyTheme\\' => 'public/themes/sloth-theme/']]])
        );
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('foo', function ($v) { return $v; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $content = file_get_contents($tmpDir . '/theme/Extensions/View/FooHelper.php');
        expect($content)->toContain('namespace MyTheme\Extensions\View');

        removeTmpDir($tmpDir);
    });

    it('falls back to Theme namespace when no matching psr-4 entry found', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('foo', function ($v) { return $v; }),
            ]);
            PHP);

        (new MigrateViewExtensions($command, $modernizer))();

        $content = file_get_contents($tmpDir . '/theme/Extensions/View/FooHelper.php');
        expect($content)->toContain('namespace Theme\Extensions\View');

        removeTmpDir($tmpDir);
    });
});

// -------------------------------------------------------------------------
// Report
// -------------------------------------------------------------------------

describe('report', function () {
    it('records generated classes in migrated report', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('obfuscate_email', function ($email) { return $email; }),
            ]);
            PHP);

        $report = (new MigrateViewExtensions($command, $modernizer))();

        expect($report['migrated'])->toContain(
            "theme.twig.filters: 'obfuscate_email' → theme/Extensions/View/ObfuscateEmailHelper.php"
        );

        removeTmpDir($tmpDir);
    });

    it('records non-closure entries as manual', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            Configure::write('theme.twig.filters', [
                new Twig_SimpleFilter('my_filter', [$this, 'myMethod']),
            ]);
            PHP);

        $report = (new MigrateViewExtensions($command, $modernizer))();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, 'my_filter'));
        removeTmpDir($tmpDir);
    });

    it('records non-array-literal argument as manual', function () {
        [$tmpDir, $command, $modernizer] = viewExtensionsSetup();
        writeViewConfig($tmpDir, <<<'PHP'
            $filters = [new Twig_SimpleFilter('foo', function ($v) { return $v; })];
            Configure::write('theme.twig.filters', $filters);
            PHP);

        $report = (new MigrateViewExtensions($command, $modernizer))();

        expect($report['manual'])->toContainWith(fn ($m) => str_contains($m, 'theme.twig.filters'));
        removeTmpDir($tmpDir);
    });
});
