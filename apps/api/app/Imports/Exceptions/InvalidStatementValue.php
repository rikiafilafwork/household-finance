<?php

namespace App\Imports\Exceptions;

use InvalidArgumentException;

/** Satu nilai sel (tanggal/nominal) tidak terbaca; baris itu jadi `invalid`. */
class InvalidStatementValue extends InvalidArgumentException {}
