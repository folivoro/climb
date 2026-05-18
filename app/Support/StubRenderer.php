<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Renders stub files by replacing {{ placeholder }} tokens with values.
 *
 * Stubs live in the /stubs directory relative to the climb application root.
 * Each placeholder is wrapped in double curly braces: {{ name }}.
 *
 * Multi-line values (e.g. docblocks, method bodies) are indented to match
 * the indentation level of the placeholder they replace.
 *
 * @since 1.0.0
 */
class StubRenderer
{
    public function __construct(private readonly string $stubsDir) {}

    /**
     * Render a stub file with the given replacements.
     *
     * @param  string               $stub         Stub filename without extension (e.g. 'ViewHelper').
     * @param  array<string, string> $replacements Map of placeholder → value.
     * @return string               Rendered content.
     *
     * @throws \RuntimeException When the stub file does not exist.
     */
    public function render(string $stub, array $replacements): string
    {
        $path = $this->stubsDir . '/' . $stub . '.stub';

        if (!file_exists($path)) {
            throw new \RuntimeException("Stub not found: {$path}");
        }

        $content = file_get_contents($path);

        foreach ($replacements as $placeholder => $value) {
            $content = $this->replaceWithIndent($content, $placeholder, $value);
        }

        return $content;
    }

    /**
     * Replace a placeholder while preserving the indentation of the line it sits on.
     *
     * If the placeholder is found indented on its own line, the replacement
     * value's subsequent lines are indented to match.
     *
     * @param string $content     Template content.
     * @param string $placeholder Placeholder name (without braces).
     * @param string $value       Replacement value.
     */
    private function replaceWithIndent(string $content, string $placeholder, string $value): string
    {
        $token = '{{ ' . $placeholder . ' }}';

        // Inline placeholder — simply replace without indent logic
        if (!preg_match('/^[ \t]*' . preg_quote($token, '/') . '$/m', $content)) {
            return str_replace($token, $value, $content);
        }

        // Block placeholder — preserve indentation of subsequent lines
        return preg_replace_callback(
            '/^([ \t]*)' . preg_quote($token, '/') . '$/m',
            function (array $matches) use ($value) {
                $indent = $matches[1];
                $lines  = explode("\n", $value);

                $indented = array_map(
                    fn ($i, $line) => $i === 0 ? $line : ($line !== '' ? $indent . $line : ''),
                    array_keys($lines),
                    $lines,
                );

                return $indent . implode("\n", $indented);
            },
            $content,
        );
    }
}
