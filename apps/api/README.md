# Personal Finance API

Laravel 13 + MySQL 8 + Sanctum. Desain lengkap ada di [`Architecture.md`](../../Architecture.md).

```sh
composer setup     # install, .env, key, migrate
composer check     # pint --test + phpstan (level 6) + pest
composer format    # pint
```

Queue, cache, dan session memakai driver `database` (tanpa Redis, ADR-009).
