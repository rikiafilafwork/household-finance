<?php

namespace App\Imports\Exceptions;

use RuntimeException;

/** Seluruh file mutasi tidak bisa dibaca (bukan sekadar satu baris yang salah). */
class InvalidStatementFile extends RuntimeException {}
