<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/input-sanitizer.php
 * Standalone smoke test for lib-level InputSanitizer behavior.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__DIR__, 2) . '/private/lib/Security/InputSanitizer.php';

use Raven\Lib\Security\InputSanitizer;

/**
 * @throws RuntimeException
 */
function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        return;
    }

    throw new RuntimeException($label . ' failed.');
}

try {
    $input = new InputSanitizer();

    assertSameValue('hello', $input->text(" \thello\n"), 'text trims and strips controls');
    assertSameValue('<b>x</b>', $input->html("<b>\0x</b>"), 'html strips null bytes');
    assertSameValue('abc-123', $input->slug(' AbC-123 '), 'slug normalization');
    assertSameValue(null, $input->slug('Bad Slug!'), 'slug validation');
    assertSameValue('user@example.test', $input->email(' USER@EXAMPLE.TEST '), 'email normalization');
    assertSameValue(null, $input->email('not-an-email'), 'email validation');
    assertSameValue('user.name-1', $input->username(' User.Name-1 '), 'username normalization');
    assertSameValue(null, $input->username('x'), 'username validation');
    assertSameValue(42, $input->int('42', 1, 100), 'int conversion');
    assertSameValue(null, $input->int('200', 1, 100), 'int bounds');

    echo "INPUT_SANITIZER_SMOKE=PASS\n";
    exit(0);
} catch (Throwable $exception) {
    echo "INPUT_SANITIZER_SMOKE=FAIL\n";
    echo "ERROR=" . $exception->getMessage() . "\n";
    exit(1);
}

