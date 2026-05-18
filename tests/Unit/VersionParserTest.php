<?php

declare(strict_types=1);

use App\Support\VersionParser;

$parser = new VersionParser();

describe('stable semver', function () use ($parser) {
    it('parses 1.2.3 as 1',  fn () => expect($parser->majorVersion('1.2.3'))->toBe(1));
    it('parses v1.2.3 as 1', fn () => expect($parser->majorVersion('v1.2.3'))->toBe(1));
    it('parses 2.0.0 as 2',  fn () => expect($parser->majorVersion('2.0.0'))->toBe(2));
    it('parses 2.1.0 as 2',  fn () => expect($parser->majorVersion('2.1.0'))->toBe(2));
    it('parses 10.0.0 as 10',fn () => expect($parser->majorVersion('10.0.0'))->toBe(10));
});

describe('numbered dev branches', function () use ($parser) {
    it('parses dev-2.x as 2', fn () => expect($parser->majorVersion('dev-2.x'))->toBe(2));
    it('parses dev-2.1 as 2', fn () => expect($parser->majorVersion('dev-2.1'))->toBe(2));
    it('parses dev-3 as 3',   fn () => expect($parser->majorVersion('dev-3'))->toBe(3));
    it('parses dev-3.x as 3', fn () => expect($parser->majorVersion('dev-3.x'))->toBe(3));
});

describe('everything else → 1', function () use ($parser) {
    it('treats dev-main as 1',             fn () => expect($parser->majorVersion('dev-main'))->toBe(1));
    it('treats dev-master as 1',           fn () => expect($parser->majorVersion('dev-master'))->toBe(1));
    it('treats dev-develop as 1',          fn () => expect($parser->majorVersion('dev-develop'))->toBe(1));
    it('treats dev-feat/my-feature as 1',  fn () => expect($parser->majorVersion('dev-feat/my-feature'))->toBe(1));
    it('treats dev-fix/some-bug as 1',     fn () => expect($parser->majorVersion('dev-fix/some-bug'))->toBe(1));
    it('treats dev-something-weird as 1',  fn () => expect($parser->majorVersion('dev-something-weird'))->toBe(1));
    it('treats 9999999-dev as 1',          fn () => expect($parser->majorVersion('9999999-dev'))->toBe(1));
    it('treats empty string as 1',         fn () => expect($parser->majorVersion(''))->toBe(1));
});
