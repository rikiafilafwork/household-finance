/**
 * Tanggal lokal user (Keputusan #12): `occurred_on` = DATE di `users.timezone`,
 * bukan di zona perangkat dan bukan UTC. Belanja 23.30 WIB tetap "hari ini".
 */
function parts(date: Date, timeZone: string) {
  const p = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
  const get = (type: string) => p.find((x) => x.type === type)!.value;
  return { y: get('year'), m: get('month'), d: get('day') };
}

/** YYYY-MM-DD hari ini di zona waktu user. */
export function todayIn(timeZone: string, now: Date = new Date()): string {
  const { y, m, d } = parts(now, timeZone);
  return `${y}-${m}-${d}`;
}

/** YYYY-MM bulan ini di zona waktu user. */
export function monthIn(timeZone: string, now: Date = new Date()): string {
  return todayIn(timeZone, now).slice(0, 7);
}

export function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split('-').map(Number) as [number, number];
  const index = y * 12 + (m - 1) + delta;
  return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`;
}

/** "2026-11" → "November 2026" */
export function formatMonth(month: string): string {
  const [y, m] = month.split('-').map(Number) as [number, number];
  return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(Date.UTC(y, m - 1, 1));
}

/** "2026-11-21" → "21 Nov 2026" (tanpa geser zona: tanggal murni). */
export function formatDate(date: string, opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }): string {
  const [y, m, d] = date.split('-').map(Number) as [number, number, number];
  return new Intl.DateTimeFormat('id-ID', { ...opts, timeZone: 'UTC' }).format(Date.UTC(y, m - 1, d));
}

/** Tanggal terakhir bulan YYYY-MM. */
export function lastDayOf(month: string): string {
  const [y, m] = month.split('-').map(Number) as [number, number];
  const day = new Date(Date.UTC(y, m, 0)).getUTCDate();
  return `${month}-${String(day).padStart(2, '0')}`;
}

/**
 * Tanggal awal untuk transaksi yang dicatat dari rencana bulan `month`: hari ini kalau bulan berjalan,
 * hari terakhir untuk bulan lalu, tanggal 1 untuk bulan depan — selalu jatuh di bulan itu.
 */
export function defaultDateIn(month: string, today: string): string {
  const current = today.slice(0, 7);
  if (month === current) return today;
  return month < current ? lastDayOf(month) : `${month}-01`;
}
