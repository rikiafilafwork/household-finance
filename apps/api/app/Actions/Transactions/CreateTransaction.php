<?php

namespace App\Actions\Transactions;

use App\Actions\Accounts\LockAccounts;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\EnvelopeCheck;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTransaction
{
    use ValidatesTransactionTarget;

    public function __construct(private readonly LockAccounts $lockAccounts) {}

    /**
     * @return array{0: Transaction, 1: bool} [transaksi, baru dibuat?]
     */
    public function handle(User $user, TransactionInput $input): array
    {
        try {
            return $this->create($user, $input);
        } catch (UniqueConstraintViolationException $e) {
            // Jaring terakhir: retry dengan client_id sama tapi ke akun LAIN tidak terserialisasi
            // oleh kunci akun, jadi bisa lolos cek bersamaan. Unique index menolak yang kalah;
            // transaksi DB-nya sudah rollback, kembalikan baris pemenang sebagai respons idempoten.
            $existing = $this->existingByClientId($user, $input->clientId);

            if ($existing === null) {
                throw $e;
            }
            if ($existing->transfer_group_id !== null) {
                throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai sebuah transfer.']);
            }

            return [$existing, false];
        }
    }

    /**
     * @return array{0: Transaction, 1: bool}
     */
    private function create(User $user, TransactionInput $input): array
    {
        return DB::transaction(function () use ($user, $input) {
            $account = $this->lockAccounts->one($user, $input->accountId);

            // Idempotensi (Keputusan #15): dicek SETELAH akun terkunci → retry paralel jadi serial.
            if ($input->clientId !== null) {
                $existing = $this->existingByClientId($user, $input->clientId);

                if ($existing !== null && $existing->transfer_group_id !== null) {
                    throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai sebuah transfer.']);
                }
                if ($existing !== null) {
                    return [$existing, false];
                }
            }

            $this->assertAccountWritable($account);
            $this->assertCategoryMatches($user, $input->categoryId, $input->type);

            $transaction = Transaction::create([
                'user_id' => $user->getKey(),
                'account_id' => $account->getKey(),
                'category_id' => $input->categoryId,
                'type' => $input->type,
                'amount' => $input->signedAmount(),
                'occurred_on' => $input->occurredOn,
                'note' => $input->note,
                'client_id' => $input->clientId,
            ]);

            $account->increment('balance', $transaction->amount);

            // Keputusan #10: cek ambang amplop setelah commit.
            EnvelopeCheck::afterTransaction($transaction);

            return [$transaction, true];
        });
    }
}
