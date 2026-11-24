import { percentOf } from '@personal-finance/shared';
import type { Dashboard } from '@/api/dashboard';
import { MoneyText } from '@/components/money/MoneyText';
import { envelopeTone, type EnvelopeTone } from '@/components/plan/planMeta';
import { cn } from '@/lib/utils';

/**
 * Chart tanpa library (seperti mobile): bar CSS untuk kategori, SVG kecil untuk tren. Angka selalu
 * ikut tertulis — chart hanya bantuan visual, bukan satu-satunya cara membaca nilainya.
 */

const actualBar: Record<EnvelopeTone, string> = { normal: 'bg-primary', warning: 'bg-amber-500', over: 'bg-destructive' };

/** Persen aktual terhadap rencana; tanpa rencana tapi ada aktual = di luar rencana (merah). */
export function categoryTone(planned: number, actual: number): EnvelopeTone {
  if (planned <= 0) return actual > 0 ? 'over' : 'normal';
  return envelopeTone(percentOf(actual, planned));
}

/** Lebar bar dalam persen dari nilai terbesar di chart (integer, tanpa float di jalur uang). */
function width(value: number, max: number): string {
  return max > 0 ? `${percentOf(value, max)}%` : '0%';
}

/** Bar horizontal berpasangan: rencana (abu-abu) dan aktual (biru → kuning ≥ 80% → merah > 100%). */
export function CategoryChart({ rows }: { rows: Dashboard['categories'] }) {
  const visible = rows.filter((r) => r.planned > 0 || r.actual > 0).sort((a, b) => Math.max(b.planned, b.actual) - Math.max(a.planned, a.actual));
  const max = Math.max(0, ...visible.map((r) => Math.max(r.planned, r.actual)));

  if (visible.length === 0) {
    return <p className="text-sm text-muted-foreground">Belum ada rencana atau pengeluaran berkategori bulan ini.</p>;
  }

  return (
    <div className="grid gap-3">
      <ul className="grid gap-3">
        {visible.map((r) => {
          const tone = categoryTone(r.planned, r.actual);
          return (
            <li key={r.id ?? 'none'} data-testid={`category-${r.id ?? 'none'}`} data-tone={tone} className="grid gap-1">
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <span className="flex min-w-0 items-center gap-2 truncate">
                  <span aria-hidden className="size-2.5 shrink-0 rounded-full bg-muted-foreground/40" style={r.color ? { backgroundColor: r.color } : undefined} />
                  {r.name}
                </span>
                <span className="whitespace-nowrap text-xs">
                  <MoneyText amount={r.actual} className={cn('font-medium', tone === 'over' && 'text-destructive')} />
                  <span className="text-muted-foreground"> / <MoneyText amount={r.planned} /></span>
                </span>
              </div>
              <div aria-hidden className="grid gap-0.5">
                <div className="h-1.5 rounded-full bg-muted"><div className="h-full rounded-full bg-muted-foreground/30" style={{ width: width(r.planned, max) }} /></div>
                <div className="h-1.5 rounded-full bg-muted"><div className={cn('h-full rounded-full', actualBar[tone])} style={{ width: width(r.actual, max) }} /></div>
              </div>
            </li>
          );
        })}
      </ul>
      <p aria-hidden className="flex gap-4 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5"><span className="h-1.5 w-4 rounded-full bg-muted-foreground/30" /> Rencana</span>
        <span className="flex items-center gap-1.5"><span className="h-1.5 w-4 rounded-full bg-primary" /> Aktual</span>
      </p>
    </div>
  );
}

const SHORT_MONTH = new Intl.DateTimeFormat('id-ID', { month: 'short', timeZone: 'UTC' });
function shortMonth(month: string): string {
  const [y, m] = month.split('-').map(Number) as [number, number];
  return SHORT_MONTH.format(Date.UTC(y, m - 1, 1));
}

/**
 * Tren 6 bulan: bar pemasukan vs pengeluaran aktual + garis rencana pengeluaran. Tabel sr-only
 * memuat angka yang sama untuk pembaca layar.
 */
export function TrendChart({ trend, month }: { trend: Dashboard['trend']; month: string }) {
  const W = 600;
  const H = 170;
  const top = 8;
  const bottom = 20;
  const plotH = H - top - bottom;
  const slot = W / Math.max(trend.length, 1);
  const bar = Math.min(22, slot / 3);
  const max = Math.max(0, ...trend.flatMap((t) => [t.income_actual, t.expense_actual, t.expense_planned]));
  // Tinggi dalam unit viewBox; pembagian di sini geometri, bukan uang.
  const y = (v: number) => top + plotH - (max > 0 ? (v / max) * plotH : 0);
  const cx = (i: number) => slot * i + slot / 2;
  const line = trend.map((t, i) => `${cx(i)},${y(t.expense_planned)}`).join(' ');

  return (
    <div className="grid gap-2">
      <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Tren pemasukan dan pengeluaran 6 bulan">
        <line x1={0} x2={W} y1={top + plotH} y2={top + plotH} className="stroke-border" />
        {trend.map((t, i) => (
          <g key={t.month}>
            <rect x={cx(i) - bar - 1} y={y(t.income_actual)} width={bar} height={top + plotH - y(t.income_actual)} rx={2} className="fill-success" />
            <rect x={cx(i) + 1} y={y(t.expense_actual)} width={bar} height={top + plotH - y(t.expense_actual)} rx={2} className="fill-sky-500" />
            <text x={cx(i)} y={H - 5} textAnchor="middle" className={cn('fill-muted-foreground text-[11px]', t.month === month && 'fill-foreground font-semibold')}>{shortMonth(t.month)}</text>
          </g>
        ))}
        {trend.length > 1 && <polyline points={line} fill="none" strokeWidth={1.5} strokeDasharray="4 3" className="stroke-amber-500" />}
        {trend.map((t, i) => <circle key={t.month} cx={cx(i)} cy={y(t.expense_planned)} r={2.5} className="fill-amber-500" />)}
      </svg>
      <p aria-hidden className="flex flex-wrap gap-4 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-success" /> Pemasukan</span>
        <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-sky-500" /> Pengeluaran</span>
        <span className="flex items-center gap-1.5"><span className="h-0.5 w-4 bg-amber-500" /> Rencana pengeluaran</span>
      </p>
      <table data-testid="trend-table" className="sr-only">
        <caption>Tren 6 bulan</caption>
        <thead><tr><th>Bulan</th><th>Pemasukan</th><th>Pengeluaran</th><th>Rencana pengeluaran</th></tr></thead>
        <tbody>
          {trend.map((t) => (
            <tr key={t.month}>
              <td>{t.month}</td>
              <td><MoneyText amount={t.income_actual} /></td>
              <td><MoneyText amount={t.expense_actual} /></td>
              <td><MoneyText amount={t.expense_planned} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
