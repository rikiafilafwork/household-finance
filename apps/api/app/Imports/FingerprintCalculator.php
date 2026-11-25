<?php

namespace App\Imports;

use InvalidArgumentException;

/**
 * Fingerprint baris mutasi untuk dedupe import (Keputusan #13, ADR-006):
 *
 *     sha256(account_id | tanggal | amount | deskripsi_dinormalisasi | occurrence_index)
 *
 * `occurrence_index` = urutan kemunculan (mulai 0) baris dengan tanggal, amount, dan deskripsi
 * ternormalisasi yang sama di SATU file. Dua kali beli kopi Rp25.000 di hari yang sama → index 0 dan 1
 * → fingerprint berbeda, jadi keduanya masuk. File yang sama diimpor ulang menghasilkan index yang
 * sama → fingerprint sama → `duplicate`.
 *
 * Batasan: akurat kalau file memuat hari yang utuh. File yang memotong di tengah hari bisa memberi
 * index berbeda untuk transaksi identik di hari itu.
 */
final class FingerprintCalculator
{
    public static function fingerprint(
        int $accountId,
        string $occurredOn,
        int $amount,
        string $description,
        int $occurrenceIndex,
    ): string {
        return hash('sha256', implode('|', [
            $accountId,
            self::date($occurredOn),
            $amount,
            DescriptionNormalizer::normalize($description),
            $occurrenceIndex,
        ]));
    }

    /**
     * Fingerprint semua baris satu file, dengan `occurrence_index` dihitung sesuai urutan baris.
     * Kunci array input dipertahankan di output.
     *
     * @param  iterable<array-key, array{occurred_on: string, amount: int, description: string}>  $lines
     * @return array<array-key, string>
     */
    public static function forLines(int $accountId, iterable $lines): array
    {
        $seen = [];
        $fingerprints = [];

        foreach ($lines as $key => $line) {
            $date = self::date($line['occurred_on']);
            $identity = $date.'|'.$line['amount'].'|'.DescriptionNormalizer::normalize($line['description']);
            $index = $seen[$identity] ?? 0;
            $seen[$identity] = $index + 1;

            $fingerprints[$key] = self::fingerprint($accountId, $date, $line['amount'], $line['description'], $index);
        }

        return $fingerprints;
    }

    private static function date(string $occurredOn): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurredOn)
            || ! checkdate((int) substr($occurredOn, 5, 2), (int) substr($occurredOn, 8, 2), (int) substr($occurredOn, 0, 4))) {
            throw new InvalidArgumentException("Tanggal harus YYYY-MM-DD yang valid, bukan '{$occurredOn}'.");
        }

        return $occurredOn;
    }
}
