<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\TestSuite;
use Tests\Support\SpaClient;
use Tests\TestCase;

/*
| Feature test memakai TestCase Laravel + RefreshDatabase.
| Unit test murni (Money, RecurrenceCalculator, dll.) tidak butuh framework.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** Klien SPA (session cookie + CSRF), lihat tests/Support/SpaClient.php. */
function spa(): SpaClient
{
    // test() mengembalikan proxy; SpaClient butuh instance TestCase yang sedang berjalan.
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('spa() hanya bisa dipakai di dalam Feature test.');
    }

    return new SpaClient($test);
}
