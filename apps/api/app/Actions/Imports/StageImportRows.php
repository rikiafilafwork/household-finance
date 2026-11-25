<?php

namespace App\Actions\Imports;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Enums\TransactionType;
use App\Imports\Exceptions\InvalidStatementFile;
use App\Imports\FingerprintCalculator;
use App\Imports\ParsedLine;
use App\Imports\ParsedStatement;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * File mutasi → baris staging `import_rows` dengan status (Keputusan #13):
 *
 *  - invalid: parser tidak bisa membaca tanggal/nominal;
 *  - duplicate: fingerprint sudah ada sebagai `transactions.import_hash` di akun ini (termasuk yang
 *    sudah dihapus, karena UNIQUE (account_id, import_hash) juga menghitungnya);
 *  - possible_duplicate: ada transaksi NON-import di akun ini dengan amount sama dan tanggal ±1 hari
 *    (kemungkinan dicatat manual). Satu transaksi manual hanya "mengklaim" satu baris CSV, jadi dua
 *    kopi di CSV vs satu kopi yang dicatat → satu possible_duplicate, satu new;
 *  - new: sisanya.
 *
 * Hanya jalan dari status `pending` (klaim atomik), jadi job yang terulang tidak memproses dua kali.
 */
class StageImportRows
{
    public const MAX_ROWS = 20_000;

    private const CHUNK = 500;

    public function handle(Import $import): void
    {
        // ownedBy, bukan query(): di worker tidak ada user login, jadi global scope membuat query kosong.
        $claimed = Import::ownedBy($this->owner($import))->whereKey($import->getKey())
            ->where('status', ImportStatus::Pending->value)
            ->update(['status' => ImportStatus::Parsing->value, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            $statement = $this->parse($import);
        } catch (InvalidStatementFile $e) {
            $this->fail($import, $e->getMessage());

            return;
        }

        $rows = $this->classify($import, $statement->lines);

        DB::transaction(function () use ($import, $rows, $statement) {
            $import->rows()->delete();
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $import->rows()->insert($chunk);
            }

            $count = fn (ImportRowStatus $status) => count(array_filter($rows, fn (array $row) => $row['status'] === $status->value));

            $import->forceFill([
                'status' => ImportStatus::Ready,
                'headers' => $statement->headers,
                'total_rows' => count($rows),
                'new_rows' => $count(ImportRowStatus::New),
                'duplicate_rows' => $count(ImportRowStatus::Duplicate),
                'possible_duplicate_rows' => $count(ImportRowStatus::PossibleDuplicate),
                'invalid_rows' => $count(ImportRowStatus::Invalid),
                'error' => null,
            ])->save();
        });
    }

    public function fail(Import $import, string $message): void
    {
        $import->forceFill(['status' => ImportStatus::Failed, 'error' => mb_substr($message, 0, 1000)])->save();
    }

    /**
     * @throws InvalidStatementFile
     */
    private function parse(Import $import): ParsedStatement
    {
        $contents = $import->file_path === null ? null : Storage::disk(Import::DISK)->get($import->file_path);
        if ($contents === null) {
            throw new InvalidStatementFile('File import tidak ditemukan.');
        }

        $parser = $import->bank_format->parser($import->mapping(), $this->uploadDate($import));

        $statement = $parser->parse($contents);
        if (count($statement->lines) > self::MAX_ROWS) {
            throw new InvalidStatementFile('File berisi lebih dari '.number_format(self::MAX_ROWS, 0, ',', '.').' baris; pecah per bulan.');
        }

        return $statement;
    }

    /**
     * @param  list<ParsedLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function classify(Import $import, array $lines): array
    {
        $valid = array_filter($lines, fn (ParsedLine $line) => $line->isValid());

        $fingerprints = FingerprintCalculator::forLines($import->account_id, array_map(fn (ParsedLine $line) => [
            'occurred_on' => (string) $line->occurredOn,
            'amount' => (int) $line->amount,
            'description' => (string) $line->description,
        ], $valid));

        $imported = $this->existingImportHashes($import, array_values($fingerprints));
        $manual = $this->manualCandidates($import, $valid);

        $rows = [];
        foreach ($lines as $index => $line) {
            $fingerprint = $fingerprints[$index] ?? null;

            $status = match (true) {
                ! $line->isValid() => ImportRowStatus::Invalid,
                isset($imported[$fingerprint]) => ImportRowStatus::Duplicate,
                $this->claimManual($manual, (string) $line->occurredOn, (int) $line->amount) => ImportRowStatus::PossibleDuplicate,
                default => ImportRowStatus::New,
            };

            $rows[] = [
                'import_id' => $import->getKey(),
                'row_number' => $line->rowNumber,
                'occurred_on' => $line->occurredOn,
                'amount' => $line->amount,
                'description' => $line->description,
                'fingerprint' => $fingerprint,
                'status' => $status->value,
                'error' => $line->error,
                'raw' => json_encode($line->raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $fingerprints
     * @return array<string, true>
     */
    private function existingImportHashes(Import $import, array $fingerprints): array
    {
        $user = $this->owner($import);
        $existing = [];

        foreach (array_chunk($fingerprints, self::CHUNK) as $chunk) {
            Transaction::ownedBy($user)->withTrashed()
                ->where('account_id', $import->account_id)
                ->whereIn('import_hash', $chunk)
                ->pluck('import_hash')
                ->each(function (?string $hash) use (&$existing) {
                    $existing[(string) $hash] = true;
                });
        }

        return $existing;
    }

    /**
     * Transaksi non-import (dicatat manual, centang plan, transfer) di rentang tanggal file ±1 hari,
     * dikelompokkan per amount: amount → daftar tanggal yang belum diklaim.
     *
     * @param  array<int, ParsedLine>  $valid
     * @return array<int, array<int, string>>
     */
    private function manualCandidates(Import $import, array $valid): array
    {
        if ($valid === []) {
            return [];
        }

        $dates = array_map(fn (ParsedLine $line) => (string) $line->occurredOn, $valid);
        $from = (new DateTimeImmutable(min($dates)))->modify('-1 day')->format('Y-m-d');
        $to = (new DateTimeImmutable(max($dates)))->modify('+1 day')->format('Y-m-d');

        $candidates = [];
        Transaction::ownedBy($this->owner($import))
            ->where('account_id', $import->account_id)
            ->whereNull('import_hash')
            ->where('type', '<>', TransactionType::Adjustment->value)
            ->whereBetween('occurred_on', [$from, $to])
            ->orderBy('occurred_on')->orderBy('id')
            ->get(['amount', 'occurred_on'])
            ->each(function (Transaction $tx) use (&$candidates) {
                $candidates[$tx->amount][] = $tx->occurred_on->format('Y-m-d');
            });

        return $candidates;
    }

    /**
     * Ambil satu transaksi manual dengan amount sama dan tanggal ±1 hari (yang terdekat dulu).
     *
     * @param  array<int, array<int, string>>  $manual
     */
    private function claimManual(array &$manual, string $date, int $amount): bool
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($manual[$amount] ?? [] as $key => $candidate) {
            $distance = abs((int) ((new DateTimeImmutable($candidate))->diff(new DateTimeImmutable($date))->format('%r%a')));
            if ($distance <= 1 && $distance < $bestDistance) {
                [$best, $bestDistance] = [$key, $distance];
            }
        }

        if ($best === null) {
            return false;
        }

        unset($manual[$amount][$best]);

        return true;
    }

    /** Tanggal upload dalam zona waktu user (Keputusan #12): acuan tahun untuk tanggal tanpa tahun. */
    private function uploadDate(Import $import): DateTimeImmutable
    {
        return new DateTimeImmutable($import->created_at->copy()->setTimezone($this->owner($import)->timezone)->format('Y-m-d'));
    }

    private function owner(Import $import): User
    {
        /** @var User */
        return User::query()->findOrFail($import->user_id);
    }
}
