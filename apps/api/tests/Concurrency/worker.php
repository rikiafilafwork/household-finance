<?php

/*
| Worker untuk test concurrency: satu proses PHP terpisah = satu "klien".
| Mem-boot Laravel, menunggu titik start bersama (barrier) supaya semua proses
| menabrak database pada saat yang sama, lalu memanggil Action dan mencetak hasil JSON.
|
| php tests/Concurrency/worker.php '{"op":"transaction",...,"start_at":1732000000.5}'
*/

use App\Actions\Transactions\CreateTransaction;
use App\Actions\Transactions\TransactionInput;
use App\Actions\Transfers\CreateTransfer;
use App\Actions\Transfers\TransferInput;
use App\Enums\TransactionType;
use App\Models\Household;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array{op: string, household_id: int, user_id: int, start_at: float, wallet_id?: int, from?: int, to?: int, amount: int, client_id: string} $job */
$job = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

while (microtime(true) < $job['start_at']) {
    usleep(200);
}

try {
    $household = Household::findOrFail($job['household_id']);
    $user = User::findOrFail($job['user_id']);

    $result = match ($job['op']) {
        'transaction' => (function () use ($household, $user, $job) {
            [$tx, $created] = app(CreateTransaction::class)->handle($household, $user, new TransactionInput(
                walletId: (int) $job['wallet_id'],
                type: TransactionType::Expense,
                amount: $job['amount'],
                occurredOn: '2026-11-21',
                clientId: $job['client_id'],
            ));

            return ['id' => (string) $tx->id, 'created' => $created];
        })(),
        'transfer' => (function () use ($household, $user, $job) {
            [$pair, $created] = app(CreateTransfer::class)->handle($household, $user, new TransferInput(
                fromWalletId: (int) $job['from'],
                toWalletId: (int) $job['to'],
                amount: $job['amount'],
                occurredOn: '2026-11-21',
                clientId: $job['client_id'],
            ));

            return ['id' => $pair->groupId(), 'created' => $created];
        })(),
        default => throw new InvalidArgumentException("op tidak dikenal: {$job['op']}"),
    };

    echo json_encode(['ok' => true] + $result);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class.': '.$e->getMessage()]);
    exit(1);
}
