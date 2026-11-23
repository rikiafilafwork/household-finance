<?php

namespace App\Actions\Plan;

/**
 * Centang item checklist. Yang null memakai default item: akun default, nominal rencana,
 * tanggal hari ini di zona waktu user, catatan = nama item.
 */
final readonly class CheckPlanItemInput
{
    public function __construct(
        public string $clientId,
        public ?int $accountId = null,
        public ?int $amount = null,       // magnitudo positif; tanda dari jenis item
        public ?string $occurredOn = null,
        public ?string $note = null,
    ) {}
}
