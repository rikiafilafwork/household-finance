/**
 * Uang di klien (web & mobile). Padanan App\Support\Money di Laravel (Architecture.md Keputusan #1).
 *
 * - Nilai uang selalu integer rupiah (`number` aman sampai 9 kuadriliun).
 * - Format Indonesia: titik = ribuan, koma = desimal. "1.250" = 1250, BUKAN 1,25.
 * - Parsing berbasis string. Jangan pernah `parseFloat` / `Number("1.250")` pada input berformat.
 */

export class InvalidMoneyFormatError extends Error {
  constructor(
    public readonly input: string,
    public readonly reason: string,
  ) {
    super(`Format nominal tidak valid "${input}": ${reason}`);
    this.name = 'InvalidMoneyFormatError';
  }
}

const GROUPED = /^\d{1,3}(?:\.\d{3})+$/;
const PLAIN = /^\d+$/;

/** "Rp1.250.000" | "1.250.000,00" | "-Rp 50.000" | "1250000" → 1250000 */
export function parseIDRInput(input: string): number {
  let value = input.replace(/\s+/gu, '');
  let negative = false;

  if (value.startsWith('-')) {
    negative = true;
    value = value.slice(1);
  }

  value = value.replace(/^rp\.?/i, '');

  if (value.startsWith('-')) {
    if (negative) throw new InvalidMoneyFormatError(input, 'tanda minus ganda');
    negative = true;
    value = value.slice(1);
  }

  if (value === '') throw new InvalidMoneyFormatError(input, 'kosong');

  const parts = value.split(',');
  if (parts.length > 2) throw new InvalidMoneyFormatError(input, 'koma lebih dari satu');

  const whole = parts[0] ?? '';
  const fraction = parts[1];

  if (fraction !== undefined) {
    if (!/^\d{1,2}$/.test(fraction)) {
      throw new InvalidMoneyFormatError(input, 'bagian desimal tidak valid');
    }
    if (/[1-9]/.test(fraction)) {
      throw new InvalidMoneyFormatError(input, 'rupiah tidak memiliki sen');
    }
  }

  let digits: string;
  if (GROUPED.test(whole)) {
    digits = whole.replaceAll('.', '');
  } else if (PLAIN.test(whole)) {
    digits = whole;
  } else {
    throw new InvalidMoneyFormatError(input, 'pemisah ribuan harus titik per 3 digit');
  }

  digits = digits.replace(/^0+/, '');
  if (digits === '') return 0;

  // Hanya digit murni yang dikonversi; di atas batas aman JS ditolak.
  if (BigInt(digits) > BigInt(Number.MAX_SAFE_INTEGER)) {
    throw new InvalidMoneyFormatError(input, 'nominal terlalu besar');
  }

  const amount = Number.parseInt(digits, 10);
  return negative ? -amount : amount;
}

/** Varian aman untuk form: null kalau tidak valid, tanpa melempar error. */
export function tryParseIDRInput(input: string): number | null {
  try {
    return parseIDRInput(input);
  } catch (error) {
    if (error instanceof InvalidMoneyFormatError) return null;
    throw error;
  }
}

function assertInteger(amount: number): void {
  if (!Number.isSafeInteger(amount)) {
    throw new TypeError(`Nominal harus integer rupiah, diterima: ${amount}`);
  }
}

/** 1250000 → "1.250.000" (tanpa prefix, untuk MoneyInput saat diketik). */
export function formatThousands(amount: number): string {
  assertInteger(amount);
  const sign = amount < 0 ? '-' : '';
  const digits = String(Math.abs(amount));
  return sign + digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

/** 1250000 → "Rp1.250.000", -50000 → "-Rp50.000" */
export function formatIDR(amount: number): string {
  assertInteger(amount);
  const sign = amount < 0 ? '-' : '';
  return `${sign}Rp${formatThousands(Math.abs(amount))}`;
}

/** Persen integer dibulatkan ke bawah, tanpa float: percentOf(80_000, 100_000) → 80. */
export function percentOf(part: number, whole: number): number {
  assertInteger(part);
  assertInteger(whole);
  if (whole <= 0) throw new RangeError('Pembagi persen harus lebih dari 0.');
  const result = (BigInt(part) * 100n) / BigInt(whole);
  // BigInt membulatkan ke arah nol; samakan dengan intdiv PHP.
  return Number(result);
}

/** spent * 100 >= limit * threshold, tanpa pembagian. */
export function reachesPercent(part: number, whole: number, threshold: number): boolean {
  return BigInt(part) * 100n >= BigInt(whole) * BigInt(threshold);
}
