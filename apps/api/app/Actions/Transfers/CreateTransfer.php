<?php

namespace App\Actions\Transfers;

use App\Actions\Accounts\LockAccounts;
use App\Actions\Transactions\ValidatesTransactionTarget;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transfer = 2 baris berpasangan (Keputusan #4): keluar di akun asal (negatif),
 * masuk di akun tujuan (positif), category NULL. client_id dipasang di baris keluar.
 */
class CreateTransfer
{
    use ValidatesTransactionTarget;

    public function __construct(private readonly LockAccounts $lockAccounts) {}

    /**
     * @return array{0: TransferPair, 1: bool} [transfer, baru dibuat?]
     */
    public function handle(User $user, TransferInput $input): array
    {
        if ($input->fromAccountId === $input->toAccountId) {
            throw ValidationException::withMessages(['to_account_id' => 'Rekening asal dan tujuan harus berbeda.']);
        }

        try {
            return $this->create($user, $input);
        } catch (UniqueConstraintViolationException $e) {
            // Lihat CreateTransaction: retry ber-client_id sama ke pasangan akun berbeda.
            $existing = $this->existingByClientId($user, $input->clientId);

            if ($existing === null) {
                throw $e;
            }
            if ($existing->transfer_group_id === null) {
                throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai transaksi lain.']);
            }

            return [TransferPair::find($user, $existing->transfer_group_id), false];
        }
    }

    /**
     * @return array{0: TransferPair, 1: bool}
     */
    private function create(User $user, TransferInput $input): array
    {
        return DB::transaction(function () use ($user, $input) {
            $accounts = $this->lockAccounts->handle($user, [$input->fromAccountId, $input->toAccountId]);

            if ($input->clientId !== null) {
                $existing = $this->existingByClientId($user, $input->clientId);

                if ($existing !== null && $existing->transfer_group_id !== null) {
                    return [TransferPair::find($user, $existing->transfer_group_id), false];
                }
                if ($existing !== null) {
                    throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai transaksi lain.']);
                }
            }

            $from = $accounts->get($input->fromAccountId);
            $to = $accounts->get($input->toAccountId);
            assert($from !== null && $to !== null);

            $this->assertAccountWritable($from, 'from_account_id');
            $this->assertAccountWritable($to, 'to_account_id');

            $groupId = (string) Str::uuid();
            $base = [
                'user_id' => $user->getKey(),
                'type' => TransactionType::Transfer,
                'occurred_on' => $input->occurredOn,
                'note' => $input->note,
                'transfer_group_id' => $groupId,
            ];

            $out = Transaction::create($base + [
                'account_id' => $from->getKey(),
                'amount' => -abs($input->amount),
                'client_id' => $input->clientId,
            ]);
            $in = Transaction::create($base + [
                'account_id' => $to->getKey(),
                'amount' => abs($input->amount),
            ]);

            $from->increment('balance', $out->amount);
            $to->increment('balance', $in->amount);

            return [TransferPair::find($user, $groupId), true];
        });
    }
}
