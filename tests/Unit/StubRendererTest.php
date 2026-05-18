<?php

declare(strict_types=1);

use App\Support\StubRenderer;

function stubsDir(): string
{
    $tmpDir = sys_get_temp_dir() . '/climb-stubs-' . uniqid();
    mkdir($tmpDir, 0755, true);

    return $tmpDir;
}

describe('StubRenderer', function () {
    it('replaces inline placeholders within a line', function () {
        $dir = stubsDir();
        file_put_contents($dir . '/Test.stub', 'public function __invoke({{ params }}){{ returnType }}');

        $result = (new StubRenderer($dir))->render('Test', [
            'params'     => '$email, $args = []',
            'returnType' => ': string',
        ]);

        expect($result)->toBe('public function __invoke($email, $args = []): string');
        removeTmpDir($dir);
    });

    it('replaces a simple placeholder', function () {
        $dir = stubsDir();
        file_put_contents($dir . '/Test.stub', 'Hello {{ name }}!');

        $result = (new StubRenderer($dir))->render('Test', ['name' => 'World']);

        expect($result)->toBe('Hello World!');
        removeTmpDir($dir);
    });

    it('replaces multiple placeholders', function () {
        $dir = stubsDir();
        file_put_contents($dir . '/Test.stub', '{{ greeting }} {{ name }}!');

        $result = (new StubRenderer($dir))->render('Test', [
            'greeting' => 'Hello',
            'name'     => 'World',
        ]);

        expect($result)->toBe('Hello World!');
        removeTmpDir($dir);
    });

    it('preserves indentation of subsequent lines in multi-line values', function () {
        $dir = stubsDir();
        file_put_contents($dir . '/Test.stub', "    {{ body }}");

        $body   = "line one\nline two\nline three";
        $result = (new StubRenderer($dir))->render('Test', ['body' => $body]);

        expect($result)->toBe("    line one\n    line two\n    line three");
        removeTmpDir($dir);
    });

    it('does not add indent to empty lines in multi-line values', function () {
        $dir = stubsDir();
        file_put_contents($dir . '/Test.stub', "    {{ body }}");

        $body   = "line one\n\nline three";
        $result = (new StubRenderer($dir))->render('Test', ['body' => $body]);

        expect($result)->toBe("    line one\n\n    line three");
        removeTmpDir($dir);
    });

    it('handles empty replacement value', function () {
        $dir = stubsDir();
        file_put_contents($dir . '/Test.stub', 'before{{ docBlock }}after');

        $result = (new StubRenderer($dir))->render('Test', ['docBlock' => '']);

        expect($result)->toBe('beforeafter');
        removeTmpDir($dir);
    });

    it('throws when stub file does not exist', function () {
        $dir = stubsDir();

        expect(fn () => (new StubRenderer($dir))->render('Missing', []))
            ->toThrow(\RuntimeException::class, 'Stub not found');

        removeTmpDir($dir);
    });
});
