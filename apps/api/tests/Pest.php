<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature test memakai TestCase Laravel + RefreshDatabase.
| Unit test murni (Money, RecurrenceCalculator, dll.) tidak butuh framework.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
