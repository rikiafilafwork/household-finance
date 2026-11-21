<?php

use function Pest\Laravel\get;

it('responds on the health check endpoint', function () {
    get('/up')->assertOk();
});

it('uses the database driver for queue, cache and session', function () {
    // Keputusan #13: tanpa Redis. Dicek dari .env.example agar tidak tertimpa env testing.
    $env = file_get_contents(base_path('.env.example'));

    expect($env)
        ->toContain('QUEUE_CONNECTION=database')
        ->toContain('CACHE_STORE=database')
        ->toContain('SESSION_DRIVER=database');
});
