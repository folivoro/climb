<?php

declare(strict_types=1);

namespace App\Modernizers\V2\Jobs;

use App\Modernizers\AbstractJob;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Migrates legacy Twig filter and function registrations to AbstractViewExtension classes.
 *
 * ## Background
 *
 * In Sloth v1, Twig filters and functions were registered via Configure::write():
 *
 * ```php
 * Configure::write('theme.twig.filters', [
 *     new TwigFilter('obfuscate_email', function ($email) { ... }),
 * ]);
 *
 * Configure::write('theme.twig.functions', [
 *     new TwigFunction('asset', function ($path) { ... }),
 * ]);
 * ```
 *
 * In Sloth v2, each filter or function becomes a dedicated class in
 * `theme/Extensions/View/` extending `AbstractViewExtension`, with the
 * closure body moved into `__invoke()`.
 *
 * ## Output
 *
 * For each `TwigFilter` entry → `{Name}Helper.php` implementing `getHelpers()`
 * For each `TwigFunction` entry → `{Name}Directive.php` implementing `getDirectives()`
 *
 * Example output for `obfuscate_email` filter:
 *
 * ```php
 * class ObfuscateEmailHelper extends AbstractViewExtension
 * {
 *     public function getHelpers(): array
 *     {
 *         return ['obfuscate_email' => $this];
 *     }
 *
 *     public function __invoke(string $email): mixed
 *     {
 *         // ... migrated closure body
 *     }
 * }
 * ```
 *
 * ## Source of truth
 *
 * Unlike MigrateConfigs, this job always uses php-parser as the source of truth —
 * never the config dump — because the closure body (source code) is required
 * and cannot be serialised to JSON.
 *
 * ## Limitations
 *
 * If a closure cannot be extracted (e.g. it is a reference to a named function
 * or a class method rather than an inline closure), the entry is recorded as
 * requiring manual migration.
 *
 * @since 1.0.0
 */
class MigrateViewExtensions extends AbstractJob
{
    /**
     * Twig class names recognised as filter constructors.
     *
     * @var list<string>
     */
    private const FILTER_CLASSES = [
        'TwigFilter',
        'Twig_SimpleFilter',
        'Twig\\TwigFilter',
    ];

    /**
     * Twig class names recognised as function constructors.
     *
     * @var list<string>
     */
    private const FUNCTION_CLASSES = [
        'TwigFunction',
        'Twig_SimpleFunction',
        'Twig\\TwigFunction',
    ];

    /**
     * Config files that may contain theme.twig.filters / theme.twig.functions.
     *
     * @var list<string>
     */
    private const SOURCE_FILES = [
        'app/config/app.config.php',
        'theme/config.php',
    ];

    /**
     * Execute the view extension migration.
     *
     * @return array<string, list<string>>
     */
    public function __invoke(): array
    {
        $this->command->info('🎨 Migrating Twig view extensions...');

        $outputDir = $this->modernizer->getThemeDir() . '/Extensions/View';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        foreach (self::SOURCE_FILES as $relativePath) {
            $file = $this->modernizer->getProjectRoot() . '/' . $relativePath;

            if (!file_exists($file)) {
                continue;
            }

            $this->processFile($file, $outputDir);
        }

        return $this->report;
    }

    /**
     * Parse a config file and extract all filter/function registrations.
     *
     * @param string $file      Absolute path to the config file.
     * @param string $outputDir Absolute path to the output directory.
     */
    private function processFile(string $file, string $outputDir): void
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $finder = new NodeFinder();
        $ast    = $parser->parse(file_get_contents($file));

        /** @var StaticCall[] $calls */
        $calls = $finder->findInstanceOf($ast, StaticCall::class);

        foreach ($calls as $call) {
            if (!$this->isConfigureWrite($call)) {
                continue;
            }

            $key = $this->extractStringArg($call, 0);

            if ($key === 'theme.twig.filters') {
                $this->processExtensions($call, $outputDir, 'filter');
            } elseif ($key === 'theme.twig.functions') {
                $this->processExtensions($call, $outputDir, 'function');
            }
        }
    }

    /**
     * Extract extension entries from a Configure::write() array argument and generate classes.
     *
     * @param StaticCall $call      The Configure::write() node.
     * @param string     $outputDir Directory to write generated classes to.
     * @param string     $type      'filter' or 'function'.
     */
    private function processExtensions(StaticCall $call, string $outputDir, string $type): void
    {
        $arrayArg = $call->args[1]->value ?? null;

        if (!$arrayArg instanceof Node\Expr\Array_) {
            $this->manual(
                "theme.twig.{$type}s: second argument is not an array literal — please migrate manually."
            );

            return;
        }

        foreach ($arrayArg->items as $item) {
            if (!$item->value instanceof New_) {
                $this->manual(
                    "theme.twig.{$type}s: non-new() entry found — please migrate manually."
                );
                continue;
            }

            $this->processEntry($item->value, $outputDir, $type);
        }
    }

    /**
     * Generate a single AbstractViewExtension class from a TwigFilter/TwigFunction instantiation.
     *
     * @param New_   $node      The `new TwigFilter(...)` or `new TwigFunction(...)` node.
     * @param string $outputDir Directory to write the generated class to.
     * @param string $type      'filter' or 'function'.
     */
    private function processEntry(New_ $node, string $outputDir, string $type): void
    {
        $className = $node->class instanceof Node\Name
            ? (string) $node->class
            : null;

        if ($className === null) {
            $this->manual("Unresolvable class in theme.twig.{$type}s — please migrate manually.");

            return;
        }

        $knownClasses = $type === 'filter' ? self::FILTER_CLASSES : self::FUNCTION_CLASSES;

        if (!in_array($className, $knownClasses, true)) {
            $this->manual(
                "Unknown Twig class '{$className}' in theme.twig.{$type}s — please migrate manually."
            );

            return;
        }

        $name = $this->extractStringArg2($node->args, 0);

        if ($name === null) {
            $this->manual(
                "theme.twig.{$type}s: could not extract name from {$className} constructor — please migrate manually."
            );

            return;
        }

        $closure = $node->args[1]->value ?? null;

        if (!$closure instanceof Node\Expr\Closure) {
            $this->manual(
                "theme.twig.{$type}s: '{$name}' does not use an inline closure — please migrate manually."
            );

            return;
        }

        $this->generateClass($name, $closure, $outputDir, $type);
    }

    /**
     * Generate and write an AbstractViewExtension class file.
     *
     * @param string               $name      The filter/function name (e.g. 'obfuscate_email').
     * @param Node\Expr\Closure    $closure   The closure node to extract params and body from.
     * @param string               $outputDir Target directory.
     * @param string               $type      'filter' or 'function'.
     */
    private function generateClass(
        string $name,
        Node\Expr\Closure $closure,
        string $outputDir,
        string $type,
    ): void {
        $printer   = new PrettyPrinter();
        $stub      = $type === 'filter' ? 'ViewHelper' : 'ViewDirective';
        $className = $this->toClassName($name) . ($type === 'filter' ? 'Helper' : 'Directive');
        $fileName  = $outputDir . '/' . $className . '.php';

        // Params joined by ', '
        $params = implode(', ', array_map(
            fn ($p) => $printer->prettyPrint([$p]),
            $closure->params,
        ));

        // Explicit return type or empty
        $returnType = $closure->returnType !== null
            ? ': ' . $printer->prettyPrint([$closure->returnType])
            : '';

        // @param docblock — include type when available
        $docBlock = '';
        if (!empty($closure->params)) {
            $paramLines = array_map(
                fn ($p) => '@param ' . ($p->type ? $printer->prettyPrint([$p->type]) . ' ' : '') . '$' . $p->var->name,
                $closure->params,
            );
            $docBlock = "/**\n * " . implode("\n * ", $paramLines) . "\n */";
        }

        // Body
        $body = $printer->prettyPrint($closure->stmts);

        $source = (new \App\Support\StubRenderer(
            dirname(__DIR__) . '/stubs'
        ))->render($stub, [
            'namespace' => $this->resolveThemeNamespace(),
            'className' => $className,
            'name'      => $name,
            'params'    => $params,
            'returnType'=> $returnType,
            'docBlock'  => $docBlock,
            'body'      => $body,
        ]);

        file_put_contents($fileName, $source);

        $this->migrated(
            "theme.twig.{$type}s: '{$name}' → theme/Extensions/View/{$className}.php"
        );
    }

    /**
     * Resolve the theme namespace from composer.json PSR-4 autoload.
     *
     * Looks for a PSR-4 entry whose path matches the theme directory.
     * Falls back to 'Theme' if no matching entry is found.
     */
    private function resolveThemeNamespace(): string
    {
        $composerFile = $this->modernizer->getProjectRoot() . '/composer.json';

        if (!file_exists($composerFile)) {
            return 'Theme';
        }

        $composer  = json_decode(file_get_contents($composerFile), true);
        $psr4      = $composer['autoload']['psr-4'] ?? [];
        $themeDir  = $this->modernizer->getThemeDir();
        $themeBase = basename($themeDir);

        foreach ($psr4 as $namespace => $path) {
            if (str_contains(rtrim(str_replace('\\', '/', $path), '/'), $themeBase)) {
                return rtrim($namespace, '\\');
            }
        }

        return 'Theme';
    }

    /**
     * Convert a snake_case filter/function name to a PascalCase class name.
     */
    private function toClassName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    }

    /**
     * Check whether a StaticCall node is a Configure::write() call.
     *
     * @param StaticCall $call The node to check.
     */
    private function isConfigureWrite(StaticCall $call): bool
    {
        return $call->class instanceof Node\Name
            && in_array(
                (string) $call->class,
                ['Configure', '\\Configure', '\\Sloth\\Configure\\Configure'],
                true,
            )
            && $call->name instanceof Node\Identifier
            && $call->name->name === 'write';
    }

    /**
     * Extract the first string argument from a StaticCall node.
     *
     * @param StaticCall $call  The call node.
     * @param int        $index Argument index.
     */
    private function extractStringArg(StaticCall $call, int $index): ?string
    {
        $value = $call->args[$index]->value ?? null;

        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * Extract a string argument from a plain args array (e.g. from New_::$args).
     *
     * @param array $args  Argument nodes.
     * @param int   $index Argument index.
     */
    private function extractStringArg2(array $args, int $index): ?string
    {
        $value = $args[$index]->value ?? null;

        return $value instanceof String_ ? $value->value : null;
    }
}
