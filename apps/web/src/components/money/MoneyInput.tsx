import { formatThousands, MAX_AMOUNT, tryParseIDRInput } from '@personal-finance/shared';
import { forwardRef, useLayoutEffect, useRef, useState, type ClipboardEvent, type ComponentProps, type FormEvent } from 'react';
import { cn } from '@/lib/utils';

/**
 * Input uang (Keputusan #1). Tampil `1.250.000` saat diketik, nilai yang keluar SELALU integer
 * rupiah (atau null kalau kosong). Tidak ada float di jalur ini:
 * - Mengetik: hanya digit (dan `-` di depan kalau allowNegative) yang diambil, lalu diformat ulang.
 * - Paste: lewat parseIDRInput, jadi `Rp1.250.000,00` → 1250000, sedangkan `1,5` ditolak
 *   (rupiah tidak punya sen). Membuang non-digit begitu saja akan membuat `1.250.000,00` → 125 juta.
 * - Caret dipertahankan setelah digit yang sama, tidak loncat ke ujung saat titik bertambah.
 */
export interface MoneyInputProps extends Omit<ComponentProps<'input'>, 'value' | 'onChange' | 'type' | 'defaultValue'> {
  value: number | null | undefined;
  onChange: (value: number | null) => void;
  allowNegative?: boolean;
  /** Batas atas absolut (default MAX_AMOUNT, sama dengan server). */
  max?: number;
  invalid?: boolean;
}

export function displayOf(value: number | null | undefined): string {
  return value === null || value === undefined ? '' : formatThousands(value);
}

/** Posisi caret setelah `digitsBefore` digit di string terformat. */
function caretAfterDigits(formatted: string, digitsBefore: number): number {
  if (digitsBefore <= 0) return formatted.startsWith('-') ? 1 : 0;
  let seen = 0;
  for (let i = 0; i < formatted.length; i++) {
    if (/\d/.test(formatted[i]!)) seen++;
    if (seen === digitsBefore) return i + 1;
  }
  return formatted.length;
}

export const MoneyInput = forwardRef<HTMLInputElement, MoneyInputProps>(function MoneyInput(
  { value, onChange, allowNegative = false, max = MAX_AMOUNT, invalid, className, onBlur, ...props },
  forwardedRef,
) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const pendingCaret = useRef<number | null>(null);
  // Baru mengetik "-" (belum ada digit): nilai masih null, tapi tanda minus harus tetap tampil.
  const [minusDraft, setMinusDraft] = useState(false);
  const display = minusDraft && (value === null || value === undefined) ? '-' : displayOf(value);

  useLayoutEffect(() => {
    const el = inputRef.current;
    if (el && pendingCaret.current !== null && document.activeElement === el) {
      el.setSelectionRange(pendingCaret.current, pendingCaret.current);
    }
    pendingCaret.current = null;
  });

  const emit = (next: number | null, formatted: string, digitsBefore: number) => {
    if (next !== null && Math.abs(next) > max) {
      // Tolak ketikan yang melewati batas; tampilan tetap nilai sebelumnya.
      pendingCaret.current = caretAfterDigits(display, digitsBefore - 1);
      onChange(value ?? null);
      return;
    }
    pendingCaret.current = caretAfterDigits(formatted, digitsBefore);
    onChange(next);
  };

  const handleInput = (event: FormEvent<HTMLInputElement>) => {
    const el = event.currentTarget;
    const raw = el.value;
    const caret = el.selectionStart ?? raw.length;
    const negative = allowNegative && raw.trimStart().startsWith('-');
    const digits = raw.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const digitsBefore = raw.slice(0, caret).replace(/\D/g, '').length - (raw.replace(/\D/g, '').length - digits.length);

    setMinusDraft(digits === '' && negative);
    if (digits === '') {
      pendingCaret.current = negative ? 1 : 0;
      onChange(null);
      return;
    }

    // Hanya digit murni yang dikonversi (parseIDRInput menolak di atas batas aman).
    const magnitude = tryParseIDRInput(digits);
    if (magnitude === null) {
      onChange(value ?? null);
      return;
    }
    const next = negative ? -magnitude : magnitude;
    emit(next, formatThousands(next), Math.max(digitsBefore, 0));
  };

  const handlePaste = (event: ClipboardEvent<HTMLInputElement>) => {
    const text = event.clipboardData.getData('text').trim();
    const el = event.currentTarget;
    // Paste parsial di tengah angka → biarkan jalur ketik biasa.
    if ((el.selectionStart ?? 0) !== 0 || (el.selectionEnd ?? 0) !== el.value.length) return;

    event.preventDefault();
    setMinusDraft(false);
    const parsed = tryParseIDRInput(text);
    if (parsed === null || (!allowNegative && parsed < 0)) return; // format tidak dikenal: abaikan
    const formatted = formatThousands(parsed);
    emit(parsed, formatted, formatted.replace(/\D/g, '').length);
  };

  return (
    <div
      className={cn(
        'flex h-9 w-full items-center rounded-md border border-input bg-transparent shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30',
        invalid && 'border-destructive ring-destructive/20 dark:ring-destructive/40',
        props.disabled && 'cursor-not-allowed opacity-50',
        className,
      )}
    >
      <span className="pl-3 text-sm text-muted-foreground select-none" aria-hidden>
        Rp
      </span>
      <input
        {...props}
        ref={(el) => {
          inputRef.current = el;
          if (typeof forwardedRef === 'function') forwardedRef(el);
          else if (forwardedRef) forwardedRef.current = el;
        }}
        type="text"
        inputMode="numeric"
        autoComplete="off"
        aria-invalid={invalid || undefined}
        value={display}
        onChange={handleInput}
        onPaste={handlePaste}
        onBlur={onBlur}
        className="h-full w-full min-w-0 bg-transparent px-2 py-1 text-right text-base tabular-nums outline-none placeholder:text-muted-foreground md:text-sm"
      />
    </div>
  );
});
