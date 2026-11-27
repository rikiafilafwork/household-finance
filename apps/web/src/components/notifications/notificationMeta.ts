import { formatIDR } from '@personal-finance/shared';
import type { AppNotification, EnvelopeThresholdPayload, ReconcileMismatchPayload, SavingsOverdrawnPayload } from '@/api/types';
import { formatMonth } from '@/lib/dates';

export type NotificationTone = 'warning' | 'over' | 'info';

export interface NotificationView {
  title: string;
  body: string;
  /** Halaman yang dibuka saat notifikasi diklik. */
  href: string;
  tone: NotificationTone;
}

/** Teks lonceng per tipe notifikasi (payload dari server; tidak ada hitung ulang di sini). */
export function describeNotification(n: AppNotification): NotificationView {
  switch (n.type) {
    case 'envelope_threshold_reached': {
      const p = n.payload as unknown as EnvelopeThresholdPayload;
      const over = p.threshold >= 100;
      return {
        title: over ? `Amplop ${p.name} terlampaui` : `Amplop ${p.name} sudah ${p.threshold}%`,
        body: `${formatMonth(p.month)}: terpakai ${formatIDR(p.spent)} dari ${formatIDR(p.planned)} (${p.percent}%).`,
        href: `/plan?month=${p.month}`,
        tone: over ? 'over' : 'warning',
      };
    }
    case 'reconcile_mismatch': {
      const p = n.payload as unknown as ReconcileMismatchPayload;
      const names = p.mismatches.map((m) => m.name).join(', ');
      return {
        title: p.fixed ? 'Saldo akun diperbaiki' : 'Saldo akun tidak cocok',
        body: `${names || 'Rekening'}: saldo tersimpan berbeda dengan jumlah transaksinya${p.fixed ? ' dan sudah disamakan' : ''}.`,
        href: '/accounts',
        tone: p.fixed ? 'info' : 'over',
      };
    }
    case 'savings_overdrawn': {
      const p = n.payload as unknown as SavingsOverdrawnPayload;
      return {
        title: 'Tabungan terpakai',
        body: `${p.name}: kurang ${formatIDR(p.shortfall)} dari total alokasi tujuan.`,
        href: '/savings',
        tone: 'over',
      };
    }
    default:
      return { title: 'Notifikasi', body: '', href: '/', tone: 'info' };
  }
}

/** "baru saja", "5 menit lalu", "3 jam lalu", "2 hari lalu", atau tanggal. */
export function timeAgo(iso: string | null, now: Date = new Date()): string {
  if (!iso) return '';
  const diff = Math.max(0, now.getTime() - new Date(iso).getTime());
  const minutes = Math.floor(diff / 60_000);
  if (minutes < 1) return 'baru saja';
  if (minutes < 60) return `${minutes} menit lalu`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} jam lalu`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days} hari lalu`;
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(iso));
}
