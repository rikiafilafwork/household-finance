<?php

/*
| Worker untuk test concurrency: satu proses PHP terpisah = satu "klien".
| Mem-boot Laravel, menunggu titik start bersama (barrier) supaya semua proses
| menabrak database pada saat yang sama, lalu memanggil Action dan mencetak hasil JSON.
|
| php tests/Concurrency/worker.php '{"op":"transaction",...,"start_at":1732000000.5}'
*/

use App\Actions\Imports\CommitImport;
use App\Actions\Plan\AutoPostDueItems;
use App\Actions\Plan\CheckPlanItem;
use App\Actions\Plan\CheckPlanItemInput;
use App\Actions\Plan\GeneratePlanFromTemplates;
use App\Actions\Plan\UncheckPlanItem;
use App\Actions\Transactions\CreateTransaction;
use App\Actions\Transactions\TransactionInput;
use App\Actions\Transfers\CreateTransfer;
use App\Actions\Transfers\TransferInput;
use App\Enums\TransactionType;
use App\Jobs\CheckEnvelopeThresholds;
use App\Models\Import;
use App\Models\PlanItem;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\PlanComparisonQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array{op: string, user_id: int, start_at: float, account_id?: int, category_id?: int, plan_item_id?: int, import_id?: int, month?: string, now?: string, from?: int, to?: int, amount: int, client_id: string} $job */
$job = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

while (microtime(true) < $job['start_at']) {
    usleep(200);
}

try {
    // Tanpa sesi login (seperti scheduler/worker): Actions harus bekerja dengan User eksplisit.
    $user = User::findOrFail($job['user_id']);

    $result = match ($job['op']) {
        'transaction' => (function () use ($user, $job) {
            [$tx, $created] = app(CreateTransaction::class)->handle($user, new TransactionInput(
                accountId: (int) $job['account_id'],
                type: TransactionType::Expense,
                categoryId: isset($job['category_id']) ? (int) $job['category_id'] : null,
                amount: $job['amount'],
                occurredOn: '2026-11-21',
                clientId: $job['client_id'],
            ));

            return ['id' => (string) $tx->id, 'created' => $created];
        })(),
        'transfer' => (function () use ($user, $job) {
            [$pair, $created] = app(CreateTransfer::class)->handle($user, new TransferInput(
                fromAccountId: (int) $job['from'],
                toAccountId: (int) $job['to'],
                amount: $job['amount'],
                occurredOn: '2026-11-21',
                clientId: $job['client_id'],
            ));

            return ['id' => $pair->groupId(), 'created' => $created];
        })(),
        'check' => (function () use ($user, $job) {
            $item = PlanItem::ownedBy($user)->findOrFail((int) $job['plan_item_id']);
            [$tx, $created] = app(CheckPlanItem::class)->handle($user, $item, new CheckPlanItemInput(clientId: $job['client_id']));

            return ['id' => (string) $tx->id, 'created' => $created];
        })(),
        'uncheck' => (function () use ($user, $job) {
            app(UncheckPlanItem::class)->handle($user, PlanItem::ownedBy($user)->findOrFail((int) $job['plan_item_id']));

            return ['id' => 'uncheck', 'created' => false];
        })(),
        'envelope-check' => (function () use ($user, $job) {
            (new CheckEnvelopeThresholds($user->getKey(), (int) $job['category_id'], (string) $job['month']))->handle(app(PlanComparisonQuery::class));

            return ['id' => 'envelope-check', 'created' => true];
        })(),
        'generate' => (function () use ($user, $job) {
            $created = app(GeneratePlanFromTemplates::class)->handle($user, PlanMonth::parse((string) $job['month']));

            return ['id' => (string) $created, 'created' => $created > 0];
        })(),
        'auto-post' => (function () use ($user, $job) {
            $result = app(AutoPostDueItems::class)->handle($user, CarbonImmutable::parse((string) $job['now'], 'UTC'));

            return ['id' => (string) count($result['posted']), 'created' => $result['posted'] !== []];
        })(),
        'commit-import' => (function () use ($user, $job) {
            $import = Import::ownedBy($user)->findOrFail((int) $job['import_id']);
            $selection = $import->rows()->whereIn('status', ['new', 'possible_duplicate'])->pluck('id')->mapWithKeys(fn (int $id) => [$id => null])->all();
            $result = app(CommitImport::class)->handle($user, $import, $selection);

            return ['id' => (string) $result['committed'], 'created' => $result['committed'] > 0];
        })(),
        default => throw new InvalidArgumentException("op tidak dikenal: {$job['op']}"),
    };

    echo json_encode(['ok' => true] + $result);
} catch (Throwable $e) {
    $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null;
    echo json_encode(['ok' => false, 'status' => $status, 'error' => $e::class.': '.$e->getMessage()]);
    exit(1);
}
