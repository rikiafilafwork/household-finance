<?php

namespace App\Actions\Transfers;

final readonly class TransferInput
{
    public function __construct(
        public int $fromWalletId,
        public int $toWalletId,
        public int $amount,      // magnitudo positif
        public string $occurredOn,
        public ?string $note = null,
        public ?string $clientId = null,
    ) {}
}
