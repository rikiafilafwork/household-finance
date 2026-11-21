<?php

namespace App\Exceptions;

use InvalidArgumentException;

class InvalidMoneyFormat extends InvalidArgumentException
{
    public static function for(string $input, string $reason): self
    {
        return new self(sprintf('Format nominal tidak valid "%s": %s', $input, $reason));
    }
}
