<?php

namespace App\Imports;

/**
 * Deskripsi mutasi → bentuk kanonik untuk fingerprint (Keputusan #13).
 *
 * Bank yang sama bisa menulis transaksi yang sama sedikit berbeda antar-ekspor (huruf besar/kecil,
 * spasi ganda, nomor referensi/jam yang ikut atau tidak). Yang dipertahankan hanya kata-kata yang
 * menjelaskan transaksinya:
 *
 *  - huruf kecil (multibyte), spasi dirapikan;
 *  - token yang memuat ≥ 6 digit dibuang (nomor referensi, nomor kartu ter-mask, stempel waktu
 *    seperti `2511/FTSCY/WS95051` atau `FT25112ABC123`); angka pendek seperti nomor gerai tetap;
 *  - tanda baca jadi spasi.
 */
final class DescriptionNormalizer
{
    /** Token dengan sebanyak ini digit atau lebih dianggap nomor referensi. */
    private const REFERENCE_MIN_DIGITS = 6;

    public static function normalize(string $description): string
    {
        $text = mb_strtolower($description, 'UTF-8');

        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_filter(
            $tokens,
            fn (string $token) => preg_match_all('/\d/u', $token) < self::REFERENCE_MIN_DIGITS,
        );

        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', implode(' ', $kept)) ?? '';

        return trim($text);
    }
}
