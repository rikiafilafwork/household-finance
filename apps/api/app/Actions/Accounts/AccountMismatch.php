<?php

namespace App\Actions\Accounts;

final readonly class AccountMismatch
{
    public function __construct(
        public int $accountId,
        public int $userId,
        public string $name,
        public int $balance,
        public int $expected,
    ) {}

    public function diff(): int
    {
        return $this->balance - $this->expected;
    }

    /**
     * @return array{account_id: int, name: string, balance: int, expected: int, diff: int}
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'name' => $this->name,
            'balance' => $this->balance,
            'expected' => $this->expected,
            'diff' => $this->diff(),
        ];
    }
}
