<?php

namespace App\Enums\Concerns;

/**
 * Helper untuk enum string-backed, mis. dipakai di migration:
 * `$table->enum('type', AccountType::values())`.
 */
trait HasValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
