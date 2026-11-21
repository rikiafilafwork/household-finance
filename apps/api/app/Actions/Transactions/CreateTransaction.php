<?php

namespace App\Actions\Transactions;

use App\Actions\Wallets\LockWallets;
use App\Enums\TransactionStatus;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateTransaction
{
    use ValidatesTransactionTarget;

    public function __construct(private readonly LockWallets $lockWallets) {}

    /**
     * @return array{0: Transaction, 1: bool} [transaksi, baru dibuat?]
     */
    public function handle(Household $household, User $actor, TransactionInput $input): array
    {
        try {
            return $this->create($household, $actor, $input);
        } catch (UniqueConstraintViolationException $e) {
            // Jaring terakhir: retry dengan client_id sama tapi ke wallet LAIN tidak terserialisasi
            // oleh kunci wallet, jadi bisa lolos cek bersamaan. Unique index menolak yang kalah;
            // transaksi DB-nya sudah rollback, kembalikan baris pemenang sebagai respons idempoten.
            $existing = $input->clientId === null ? null : Transaction::query()
                ->where('household_id', $household->getKey())
                ->where('client_id', $input->clientId)
                ->first();

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
    private function create(Household $household, User $actor, TransactionInput $input): array
    {
        return DB::transaction(function () use ($household, $actor, $input) {
            $wallet = $this->lockWallets->one($input->walletId);

            // Idempotensi (Keputusan #11): dicek SETELAH wallet terkunci → retry paralel jadi serial.
            if ($input->clientId !== null) {
                $existing = Transaction::query()
                    ->where('household_id', $household->getKey())
                    ->where('client_id', $input->clientId)
                    ->first();

                if ($existing !== null && $existing->transfer_group_id !== null) {
                    throw ValidationException::withMessages(['client_id' => 'client_id sudah dipakai sebuah transfer.']);
                }
                if ($existing !== null) {
                    return [$existing, false];
                }
            }

            $this->assertWalletWritable($wallet, $household, $actor);
            $this->assertCategoryMatches($input->categoryId, $input->type, $household);

            $transaction = Transaction::create([
                'household_id' => $household->getKey(),
                'wallet_id' => $wallet->getKey(),
                'category_id' => $input->categoryId,
                'type' => $input->type,
                'status' => $input->status,
                'amount' => $input->signedAmount(),
                'occurred_on' => $input->occurredOn,
                'note' => $input->note,
                'client_id' => $input->clientId,
                'created_by' => $actor->getKey(),
            ]);

            if ($input->status === TransactionStatus::Posted) {
                $wallet->increment('balance', $transaction->amount);
            }

            return [$transaction, true];
        });
    }
}
