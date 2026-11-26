<?php

namespace App\Actions\Imports;

use App\Actions\Accounts\LockAccounts;
use App\Actions\Transactions\ValidatesTransactionTarget;
use App\Enums\CategoryKind;
use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Enums\TransactionType;
use App\Imports\CategorySuggester;
use App\Jobs\CheckEnvelopeThresholds;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Baris staging yang dipilih → transaksi (Keputusan #13, § Minggu 6).
 *
 * Per chunk (500 baris) satu transaksi DB: kunci akun (lockForUpdate) → buang fingerprint yang sudah
 * jadi transaksi → insert → saldo ditambah SEKALI dengan jumlah baris yang benar-benar masuk →
 * baris staging ditandai `committed` dengan transaction_id-nya. Karena ketiganya atomik per chunk,
 * commit yang gagal di tengah lalu diulang hanya meneruskan chunk yang belum masuk: tidak dobel,
 * dan reconcile tetap 0. `insertOrIgnore` pada UNIQUE (account_id, import_hash) adalah jaring kedua.
 *
 * Transaksi import tidak terhubung ke item plan (plan_item_id NULL): pengeluarannya masuk amplop
 * kategorinya, atau "di luar rencana" kalau tanpa kategori.
 */
class CommitImport
{
    use ValidatesTransactionTarget;

    /** Status baris yang boleh dipilih untuk di-commit. */
    public const SELECTABLE = [ImportRowStatus::New, ImportRowStatus::PossibleDuplicate];

    public function __construct(
        private readonly LockAccounts $lockAccounts,
        private readonly int $chunkSize = 500,
    ) {}

    /**
     * @param  array<int, int|null>  $selection  import_row_id → category_id (NULL = tanpa kategori)
     * @return array{committed: int, duplicate: int, skipped: int}
     */
    public function handle(User $user, Import $import, array $selection): array
    {
        $this->claim($user, $import);

        $rows = $this->selectedRows($import, $selection);
        $this->assertCategories($user, $rows, $selection);

        $committed = 0;
        $duplicate = 0;
        $envelopes = [];

        foreach (array_chunk($rows, $this->chunkSize) as $chunk) {
            [$inserted, $dupes, $touched] = $this->commitChunk($user, $import, $chunk, $selection);
            $committed += $inserted;
            $duplicate += $dupes;
            $envelopes += $touched;
        }

        $skipped = $this->finish($user, $import);
        $this->learnRules($user, $import);

        foreach ($envelopes as $key => $_) {
            [$categoryId, $month] = explode('|', $key);
            CheckEnvelopeThresholds::dispatch($user->id, (int) $categoryId, $month);
        }

        return ['committed' => $committed, 'duplicate' => $duplicate, 'skipped' => $skipped];
    }

    /** `ready` → `committing`. `committing` boleh diulang (commit sebelumnya gagal di tengah). */
    private function claim(User $user, Import $import): void
    {
        // SELECT ... FOR UPDATE, bukan UPDATE ... WHERE status: MySQL melaporkan 0 baris kalau nilainya
        // tidak berubah, jadi retry dari `committing` di detik yang sama akan tampak "gagal diklaim".
        $status = DB::transaction(function () use ($user, $import) {
            $locked = Import::ownedBy($user)->whereKey($import->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [ImportStatus::Ready, ImportStatus::Committing], true)) {
                $locked->forceFill(['status' => ImportStatus::Committing])->save();
            }

            return $locked->status;
        });

        if ($status !== ImportStatus::Committing) {
            throw ValidationException::withMessages(['import' => match ($status) {
                ImportStatus::Committed => 'Import ini sudah di-commit.',
                ImportStatus::Failed => 'Import ini gagal diproses; unggah ulang filenya.',
                default => 'Import ini belum selesai diproses.',
            }]);
        }
        $import->refresh();
    }

    /**
     * Baris terpilih yang belum di-commit (retry melewati yang sudah). Memilih baris duplicate /
     * invalid, atau id dari import lain, ditolak.
     *
     * @param  array<int, int|null>  $selection
     * @return list<ImportRow>
     */
    private function selectedRows(Import $import, array $selection): array
    {
        $rows = $import->rows()->whereKey(array_keys($selection))->orderBy('row_number')->orderBy('id')->get();

        $unknown = array_diff(array_keys($selection), $rows->modelKeys());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['rows' => 'Baris tidak ditemukan di import ini: '.implode(', ', array_slice($unknown, 0, 10)).'.']);
        }

        $notSelectable = $rows->reject(fn (ImportRow $row) => in_array($row->status, [...self::SELECTABLE, ImportRowStatus::Committed], true));
        if ($notSelectable->isNotEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Baris duplikat/invalid tidak bisa di-commit (baris file '
                .$notSelectable->take(10)->pluck('row_number')->implode(', ').').']);
        }

        return array_values($rows->filter(fn (ImportRow $row) => $row->status !== ImportRowStatus::Committed)->all());
    }

    /**
     * Kategori milik user, aktif, dan jenisnya cocok dengan arah nominal (masuk = income).
     *
     * @param  list<ImportRow>  $rows
     * @param  array<int, int|null>  $selection
     */
    private function assertCategories(User $user, array $rows, array $selection): void
    {
        $ids = array_values(array_unique(array_filter($selection, fn (?int $id) => $id !== null)));
        $categories = Category::ownedBy($user)->whereKey($ids)->get()->keyBy('id');

        foreach ($rows as $row) {
            $categoryId = $selection[$row->id];
            if ($categoryId === null) {
                continue;
            }

            $category = $categories->get($categoryId);
            $kind = (int) $row->amount > 0 ? CategoryKind::Income : CategoryKind::Expense;
            $error = match (true) {
                $category === null => 'kategori tidak ditemukan',
                $category->archived_at !== null => 'kategori sudah diarsip',
                $category->kind !== $kind => $kind === CategoryKind::Income ? 'uang masuk butuh kategori pemasukan' : 'uang keluar butuh kategori pengeluaran',
                default => null,
            };

            if ($error !== null) {
                throw ValidationException::withMessages(['rows' => "Baris file {$row->row_number}: {$error}."]);
            }
        }
    }

    /**
     * @param  list<ImportRow>  $chunk
     * @param  array<int, int|null>  $selection
     * @return array{int, int, array<string, true>} [masuk, ternyata duplikat, amplop yang tersentuh]
     */
    private function commitChunk(User $user, Import $import, array $chunk, array $selection): array
    {
        return DB::transaction(function () use ($user, $import, $chunk, $selection) {
            $account = $this->lockAccounts->one($user, $import->account_id);
            $this->assertAccountWritable($account);

            // Commit paralel untuk import yang SAMA (klik dua kali, retry) bisa sudah memasukkan chunk
            // ini sebelum kunci kita dapat. Baris itu dilewati; kalau tidak, fingerprint-nya terlihat
            // "sudah ada" dan baris yang benar-benar committed tertimpa jadi duplicate.
            $done = ImportRow::query()->whereKey(array_map(fn (ImportRow $row) => $row->id, $chunk))
                ->where('status', ImportRowStatus::Committed->value)->pluck('id')->flip()->all();
            $chunk = array_values(array_filter($chunk, fn (ImportRow $row) => ! isset($done[$row->id])));
            if ($chunk === []) {
                return [0, 0, []];
            }

            // Di bawah kunci akun: semua commit import ke akun ini serial, jadi cek ini akurat.
            $existing = Transaction::ownedBy($user)->withTrashed()
                ->where('account_id', $account->id)
                ->whereIn('import_hash', array_map(fn (ImportRow $row) => $row->fingerprint, $chunk))
                ->pluck('id', 'import_hash')
                ->all();

            $now = now();
            $fresh = [];
            $envelopes = [];
            foreach ($chunk as $row) {
                if (isset($existing[$row->fingerprint])) {
                    continue;
                }
                $type = (int) $row->amount > 0 ? TransactionType::Income : TransactionType::Expense;
                $categoryId = $selection[$row->id];
                $fresh[] = [
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'category_id' => $categoryId,
                    'type' => $type->value,
                    'amount' => $row->amount,
                    'occurred_on' => $row->occurred_on,
                    'note' => $row->description === '' ? null : $row->description,
                    'import_hash' => $row->fingerprint,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($type === TransactionType::Expense && $categoryId !== null) {
                    $month = PlanMonth::fromDate(CarbonImmutable::parse((string) $row->occurred_on))->firstDay();
                    $envelopes[$categoryId.'|'.$month] = true;
                }
            }

            $inserted = $fresh === [] ? 0 : Transaction::ownedBy($user)->insertOrIgnore($fresh);
            if ($inserted !== count($fresh)) {
                // Tidak mungkin di bawah kunci akun; kalau terjadi, saldo jangan sampai meleset.
                throw new RuntimeException('Sebagian baris import bentrok dengan transaksi lain; ulangi commit.');
            }

            $account->increment('balance', array_sum(array_column($fresh, 'amount')));

            $ids = Transaction::ownedBy($user)->withTrashed()
                ->where('account_id', $account->id)
                ->whereIn('import_hash', array_map(fn (ImportRow $row) => $row->fingerprint, $chunk))
                ->pluck('id', 'import_hash')
                ->all();

            foreach ($chunk as $row) {
                $isDuplicate = isset($existing[$row->fingerprint]);
                ImportRow::query()->whereKey($row->id)->update([
                    'status' => ($isDuplicate ? ImportRowStatus::Duplicate : ImportRowStatus::Committed)->value,
                    'category_id' => $isDuplicate ? null : $selection[$row->id],
                    'transaction_id' => $isDuplicate ? null : $ids[$row->fingerprint],
                ]);
            }

            return [count($fresh), count($existing), $envelopes];
        });
    }

    /**
     * Kategori yang dipilih user (termasuk koreksi atas saran) jadi aturan untuk import berikutnya.
     * Dari semua baris committed import ini, jadi retry tidak kehilangan apa pun; kata kunci yang
     * sama di satu file → baris terakhir menang, aturan lama dengan kata kunci sama ditimpa.
     */
    private function learnRules(User $user, Import $import): void
    {
        $rules = [];
        $now = now();
        $import->rows()->where('status', ImportRowStatus::Committed->value)->whereNotNull('category_id')
            ->orderBy('row_number')->get(['description', 'category_id'])
            ->each(function (ImportRow $row) use (&$rules, $user, $now) {
                $keyword = CategorySuggester::keywordFor((string) $row->description);
                if ($keyword !== null) {
                    $rules[$keyword] = ['user_id' => $user->id, 'keyword' => $keyword, 'category_id' => $row->category_id, 'created_at' => $now, 'updated_at' => $now];
                }
            });

        if ($rules !== []) {
            CategoryRule::ownedBy($user)->upsert(array_values($rules), ['user_id', 'keyword'], ['category_id', 'updated_at']);
        }
    }

    /**
     * Baris yang tidak dipilih → skipped, angka ringkasan diperbarui, file dihapus dari disk privat.
     *
     * @return int jumlah baris yang dilewati
     */
    private function finish(User $user, Import $import): int
    {
        return DB::transaction(function () use ($user, $import) {
            $skipped = $import->rows()
                ->whereIn('status', array_map(fn (ImportRowStatus $s) => $s->value, self::SELECTABLE))
                ->update(['status' => ImportRowStatus::Skipped->value]);

            $count = fn (ImportRowStatus $status) => $import->rows()->where('status', $status->value)->count();

            $path = $import->file_path;
            Import::ownedBy($user)->whereKey($import->getKey())->update([
                'status' => ImportStatus::Committed->value,
                'duplicate_rows' => $count(ImportRowStatus::Duplicate),
                'committed_rows' => $count(ImportRowStatus::Committed),
                'skipped_rows' => $count(ImportRowStatus::Skipped),
                'file_path' => null,
                'updated_at' => now(),
            ]);

            if ($path !== null) {
                DB::afterCommit(fn () => Storage::disk(Import::DISK)->delete($path));
            }

            return $skipped;
        });
    }
}
