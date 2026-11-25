<?php

namespace App\Imports;

/**
 * Satu baris mutasi hasil parser: valid (tanggal, amount ber-tanda, deskripsi) atau invalid (alasan).
 */
final readonly class ParsedLine
{
    /**
     * @param  array<int|string, string>  $raw  isi sel asli (berkunci nama header kalau ada)
     */
    private function __construct(
        public int $rowNumber,
        public array $raw,
        public ?string $occurredOn,
        public ?int $amount,
        public ?string $description,
        public ?string $error,
    ) {}

    /** @param  array<int|string, string>  $raw */
    public static function valid(int $rowNumber, array $raw, string $occurredOn, int $amount, string $description): self
    {
        return new self($rowNumber, $raw, $occurredOn, $amount, mb_substr($description, 0, 255), null);
    }

    /** @param  array<int|string, string>  $raw */
    public static function invalid(int $rowNumber, array $raw, string $error): self
    {
        return new self($rowNumber, $raw, null, null, null, mb_substr($error, 0, 255));
    }

    /**
     * @phpstan-assert-if-true !null $this->occurredOn
     * @phpstan-assert-if-true !null $this->amount
     * @phpstan-assert-if-true !null $this->description
     *
     * @phpstan-assert-if-false !null $this->error
     */
    public function isValid(): bool
    {
        return $this->error === null;
    }
}
