import { formatIDR } from '@household-finance/shared';
import { cn } from '@/lib/utils';

/**
 * Tampilan rupiah. `tone="signed"`: positif hijau (dengan +), negatif tetap netral dengan −,
 * supaya daftar pengeluaran tidak "merah semua". `tone="balance"`: negatif merah (saldo minus).
 */
export function MoneyText({
  amount,
  tone = 'plain',
  className,
}: {
  amount: number;
  tone?: 'plain' | 'signed' | 'balance';
  className?: string;
}) {
  const text = tone === 'signed' && amount > 0 ? `+${formatIDR(amount)}` : formatIDR(amount).replace(/^-/, '−');

  return (
    <span
      className={cn(
        'tabular-nums whitespace-nowrap',
        tone === 'signed' && amount > 0 && 'text-success',
        tone === 'balance' && amount < 0 && 'text-destructive',
        className,
      )}
    >
      {text}
    </span>
  );
}
