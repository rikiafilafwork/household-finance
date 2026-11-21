<?php

namespace App\Actions\Wallets;

final readonly class WalletMismatch
{
    public function __construct(
        public int $walletId,
        public int $householdId,
        public string $name,
        public int $balance,
        public int $expected,
    ) {}

    public function diff(): int
    {
        return $this->balance - $this->expected;
    }

    /**
     * @return array{wallet_id: int, name: string, balance: int, expected: int, diff: int}
     */
    public function toArray(): array
    {
        return [
            'wallet_id' => $this->walletId,
            'name' => $this->name,
            'balance' => $this->balance,
            'expected' => $this->expected,
            'diff' => $this->diff(),
        ];
    }
}
