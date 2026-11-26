<?php

namespace App\Imports;

use App\Enums\CategoryKind;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\User;

/**
 * Kategori otomatis untuk baris import (stretch Minggu 6), dari aturan yang dipelajari ketika user
 * memilih kategori saat commit.
 *
 *  - Kata kunci = deskripsi ternormalisasi (DescriptionNormalizer) tanpa token yang memuat angka,
 *    jadi `INDOMARET 1234` dan `INDOMARET 5678` sama-sama `indomaret`.
 *  - Cocok kalau kata-kata kata kunci muncul BERURUTAN dan UTUH di deskripsi (bukan potongan kata:
 *    `pln` tidak cocok dengan `kaplnx`). Kalau beberapa cocok, yang terpanjang menang
 *    (`trsf e banking db budi` mengalahkan `trsf e banking db`).
 *  - Kategori yang diarsip/dihapus atau jenisnya tidak cocok dengan arah uang tidak disarankan.
 */
final class CategorySuggester
{
    public const MAX_KEYWORD_LENGTH = 100;

    /**
     * @param  array<string, int>  $rules  kata kunci → category_id
     * @param  array<int, CategoryKind>  $kinds  category_id aktif → jenisnya
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $kinds,
    ) {}

    public static function for(User $user): self
    {
        return new self(
            CategoryRule::ownedBy($user)->orderBy('id')->pluck('category_id', 'keyword')->map(fn ($id) => (int) $id)->all(),
            Category::ownedBy($user)->whereNull('archived_at')->get(['id', 'kind'])
                ->mapWithKeys(fn (Category $c) => [(int) $c->id => $c->kind])->all(),
        );
    }

    /** Kata kunci aturan untuk deskripsi ini; null kalau tidak ada kata yang tersisa. */
    public static function keywordFor(string $description): ?string
    {
        $tokens = self::tokens($description);
        $keyword = '';
        foreach ($tokens as $token) {
            $next = $keyword === '' ? $token : "{$keyword} {$token}";
            if (mb_strlen($next) > self::MAX_KEYWORD_LENGTH) {
                break;
            }
            $keyword = $next;
        }

        return $keyword === '' ? null : $keyword;
    }

    /** category_id yang disarankan untuk baris mutasi ini, atau null. */
    public function suggest(string $description, int $amount): ?int
    {
        if ($amount === 0 || $this->rules === []) {
            return null;
        }

        $haystack = ' '.implode(' ', self::tokens($description)).' ';
        $kind = $amount > 0 ? CategoryKind::Income : CategoryKind::Expense;

        $best = null;
        $bestLength = 0;
        foreach ($this->rules as $keyword => $categoryId) {
            $keyword = (string) $keyword;
            if (($this->kinds[$categoryId] ?? null) !== $kind || ! str_contains($haystack, " {$keyword} ")) {
                continue;
            }
            $length = mb_strlen($keyword);
            if ($length >= $bestLength) {   // sama panjang → aturan yang lebih baru (urutan id)
                [$best, $bestLength] = [$categoryId, $length];
            }
        }

        return $best;
    }

    /** @return list<string> */
    private static function tokens(string $description): array
    {
        $words = explode(' ', DescriptionNormalizer::normalize($description));

        return array_values(array_filter($words, fn (string $w) => $w !== '' && ! preg_match('/\d/u', $w)));
    }
}
