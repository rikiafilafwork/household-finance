<?php

namespace App\Queries;

use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Enums\TransactionType;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use Illuminate\Support\Collection;

/**
 * SATU sumber angka rencana vs aktual (Keputusan #7, #11), dipakai Rencana, Dashboard, Laporan.
 *
 * Definisi (semua nominal positif, integer rupiah):
 *  - Rencana      = SUM(planned_amount) item bulan itu yang tidak di-skip.
 *  - Aktual checklist = transaksi yang terhubung ke item (plan_item_id), berapa pun tanggalnya:
 *                   tagihan November yang dibayar 30 Oktober tetap tagihan November.
 *  - Aktual amplop    = transaksi bulan itu, kategori & jenis sama, yang TIDAK terhubung ke checklist.
 *  - Di luar rencana  = transaksi bulan itu yang tidak terhubung dan kategorinya tidak punya amplop
 *                   (amplop yang di-skip dianggap tidak ada).
 *  - Aktual = checklist + amplop + di luar rencana (tanpa dobel hitung: transaksi centang tidak
 *    masuk amplop kategorinya; transaksi terhubung ke item bulan LAIN tidak dihitung di bulan ini).
 *  - Belum dibayar = rencana item checklist pengeluaran yang belum dicentang (dan tidak di-skip).
 *  - Sisa ruang amplop = Σ max(0, rencana − aktual) amplop yang tidak di-skip (amplop yang
 *                   jebol tidak mengurangi ruang amplop lain).
 *  - Sisa anggaran = rencana pengeluaran − aktual pengeluaran (boleh negatif).
 *  - Masih akan keluar = belum dibayar + sisa ruang amplop pengeluaran (dasar "uang bebas" di web:
 *                   saldo akun − masih akan keluar).
 * Transfer & adjustment tidak pernah dihitung.
 */
final class PlanComparisonQuery
{
    public function for(User $user, PlanMonth $month): PlanComparison
    {
        $items = PlanItem::ownedBy($user)->forMonth($month->firstDay())
            ->orderBy('sort_order')->orderByRaw('due_on IS NULL, due_on')->orderBy('id')
            ->get();

        /** @var Collection<int, Transaction> $linked transaksi centang item bulan ini, per plan_item_id */
        $linked = Transaction::ownedBy($user)
            ->whereIn('plan_item_id', $items->modelKeys())
            ->get(['id', 'plan_item_id', 'account_id', 'amount', 'occurred_on'])
            ->keyBy('plan_item_id');

        // Transaksi bulan ini yang tidak terhubung ke checklist, per (jenis, kategori).
        /** @var Collection<string, int> $unlinked */
        $unlinked = Transaction::ownedBy($user)
            ->whereNull('plan_item_id')
            ->whereIn('type', [TransactionType::Income->value, TransactionType::Expense->value])
            ->whereBetween('occurred_on', [$month->firstDay(), $month->lastDay()])
            ->groupBy('type', 'category_id')
            ->selectRaw('type, category_id, SUM(amount) AS total')
            ->get()
            ->mapWithKeys(fn (Transaction $r) => [
                self::key((string) $r->getRawOriginal('type'), $r->category_id === null ? null : (int) $r->category_id) => abs((int) $r->getRawOriginal('total')),
            ]);

        $envelopeKeys = $items
            ->filter(fn (PlanItem $i) => $i->mode === PlanMode::Envelope && $i->skipped_at === null)
            ->mapWithKeys(fn (PlanItem $i) => [self::key($i->type->value, $i->category_id) => $i->getKey()]);

        $rows = $items->map(function (PlanItem $item) use ($linked, $unlinked) {
            $tx = $linked->get($item->getKey());
            $actual = $item->mode === PlanMode::Checklist
                ? ($tx !== null ? abs($tx->amount) : 0)
                : ($item->skipped_at === null ? (int) $unlinked->get(self::key($item->type->value, $item->category_id), 0) : 0);

            return new PlanItemComparison($item, $actual, $tx);
        });

        $unplanned = $unlinked->filter(fn (int $total, string $key) => ! $envelopeKeys->has($key));

        $categoryIds = $items->pluck('category_id')
            ->merge($unlinked->keys()->map(fn (string $k) => self::parseKey($k)[1]))
            ->filter()->unique()->values();
        $categories = Category::ownedBy($user)->whereKey($categoryIds)->get()->keyBy('id');

        return new PlanComparison(
            month: $month,
            items: $rows->values(),
            totals: [
                'income' => $this->totals($rows, $unplanned, PlanItemType::Income),
                'expense' => $this->totals($rows, $unplanned, PlanItemType::Expense),
            ],
            categories: $this->byCategory($rows, $unlinked, $categories),
        );
    }

    /**
     * Aktual SATU amplop dengan definisi yang sama persis dengan for(): transaksi bulan itu,
     * jenis + kategori sama, tidak terhubung ke checklist. Dipakai job alert (tanpa hitung sebulan penuh).
     */
    public function envelopeActual(PlanItem $envelope): int
    {
        $month = PlanMonth::fromDate($envelope->month);

        return abs((int) Transaction::ownedBy($envelope->user_id)
            ->whereNull('plan_item_id')
            ->where('type', $envelope->type->value)
            ->where('category_id', $envelope->category_id)
            ->whereBetween('occurred_on', [$month->firstDay(), $month->lastDay()])
            ->sum('amount'));
    }

    /**
     * @param  Collection<int, PlanItemComparison>  $rows
     * @param  Collection<string, int>  $unplanned
     * @return array{planned: int, actual: int, unplanned: int, unpaid: int, envelope_remaining: int}
     */
    private function totals(Collection $rows, Collection $unplanned, PlanItemType $type): array
    {
        $ofType = $rows->filter(fn (PlanItemComparison $r) => $r->item->type === $type && $r->item->skipped_at === null);
        $unplannedTotal = (int) $unplanned->filter(fn (int $v, string $k) => str_starts_with($k, $type->value.':'))->sum();

        return [
            'planned' => (int) $ofType->sum(fn (PlanItemComparison $r) => $r->item->planned_amount),
            'actual' => (int) $ofType->sum(fn (PlanItemComparison $r) => $r->actual) + $unplannedTotal,
            'unplanned' => $unplannedTotal,
            'unpaid' => (int) $ofType
                ->filter(fn (PlanItemComparison $r) => $r->item->mode === PlanMode::Checklist && $r->transaction === null)
                ->sum(fn (PlanItemComparison $r) => $r->item->planned_amount),
            'envelope_remaining' => (int) $ofType
                ->filter(fn (PlanItemComparison $r) => $r->item->mode === PlanMode::Envelope)
                ->sum(fn (PlanItemComparison $r) => max(0, $r->item->planned_amount - $r->actual)),
        ];
    }

    /**
     * Rencana vs aktual per kategori (untuk chart). Aktual kategori = transaksi centang item kategori
     * itu + transaksi tidak terhubung bulan ini di kategori itu. Jumlah semua baris = total aktual.
     *
     * @param  Collection<int, PlanItemComparison>  $rows
     * @param  Collection<string, int>  $unlinked
     * @param  Collection<int, Category>  $categories
     * @return list<array{type: string, category_id: int|null, name: string, planned: int, actual: int}>
     */
    private function byCategory(Collection $rows, Collection $unlinked, Collection $categories): array
    {
        $acc = [];
        $add = function (string $type, ?int $categoryId, int $planned, int $actual) use (&$acc, $categories) {
            $key = self::key($type, $categoryId);
            $acc[$key] ??= [
                'type' => $type,
                'category_id' => $categoryId,
                'name' => $categoryId === null ? 'Tanpa kategori' : ($categories->get($categoryId)->name ?? '—'),
                'planned' => 0,
                'actual' => 0,
            ];
            $acc[$key]['planned'] += $planned;
            $acc[$key]['actual'] += $actual;
        };

        foreach ($rows as $row) {
            if ($row->item->skipped_at !== null) {
                continue;
            }
            // Amplop: aktualnya diambil dari $unlinked di bawah supaya tidak dobel.
            $add($row->item->type->value, $row->item->category_id, $row->item->planned_amount,
                $row->item->mode === PlanMode::Checklist ? $row->actual : 0);
        }
        foreach ($unlinked as $key => $total) {
            [$type, $categoryId] = self::parseKey($key);
            $add($type, $categoryId, 0, $total);
        }

        $list = array_values($acc);
        usort($list, fn (array $a, array $b) => [$a['type'] === 'income' ? 0 : 1, -$a['planned'], -$a['actual'], $a['name']]
            <=> [$b['type'] === 'income' ? 0 : 1, -$b['planned'], -$b['actual'], $b['name']]);

        return $list;
    }

    private static function key(string $type, ?int $categoryId): string
    {
        return $type.':'.($categoryId ?? '-');
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function parseKey(string $key): array
    {
        [$type, $id] = explode(':', $key, 2);

        return [$type, $id === '-' ? null : (int) $id];
    }
}
