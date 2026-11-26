import { api, unwrap } from './client';
import type { Id } from './types';

/** Import CSV mutasi rekening (Keputusan #13). Lihat apps/api/app/Http/Resources/Import*Resource. */
export type ImportStatus = 'pending' | 'parsing' | 'ready' | 'committing' | 'committed' | 'failed';
export type ImportRowStatus = 'new' | 'duplicate' | 'possible_duplicate' | 'invalid' | 'committed' | 'skipped';
export type DateFormat = (typeof DATE_FORMATS)[number];

/** Sama dengan App\Imports\StatementDate::FORMATS. */
export const DATE_FORMATS = [
  'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd/m/y', 'd-m-y', 'm/d/Y',
  'd M Y', 'd-M-Y', 'd M y', 'd-M-y',
  'd/m', 'd-m', 'd M',
] as const;

/** Kolom dirujuk dengan nama header (kalau `has_header`) atau indeks mulai 0. */
export type ColumnRef = string | number;

/** Sama dengan App\Imports\ColumnMapping::toArray(). */
export interface ColumnMapping {
  has_header?: boolean;
  skip_rows?: number;
  delimiter?: ',' | ';' | '\t';
  date: ColumnRef;
  date_format: DateFormat;
  year?: number;
  description: ColumnRef[];
  amount?: ColumnRef;
  direction?: ColumnRef;
  debit?: ColumnRef;
  credit?: ColumnRef;
  decimal_separator?: ',' | '.';
}

export interface StatementImport {
  id: Id;
  account_id: Id;
  format: 'generic';
  column_mapping: ColumnMapping | null;
  original_filename: string;
  headers: string[] | null;
  status: ImportStatus;
  counts: { total: number; new: number; duplicate: number; possible_duplicate: number; invalid: number; committed: number; skipped: number };
  error: string | null;
  created_at: string;
}

export interface ImportRow {
  id: Id;
  row_number: number; // nomor baris di file (seperti di Excel)
  occurred_on: string | null;
  amount: number | null; // ber-tanda
  description: string | null;
  status: ImportRowStatus;
  category_id: Id | null;
  transaction_id: Id | null;
  error: string | null;
  raw: string[]; // sel asli, urutan sesuai `headers`
}

export interface CommitResult {
  import: StatementImport;
  meta: { committed: number; duplicate: number; skipped: number };
}

/** Status yang masih menunggu worker; halaman preview mem-poll selama ini. */
export const PROCESSING: readonly ImportStatus[] = ['pending', 'parsing'];

const base = '/imports';

export const importsApi = {
  list: () => unwrap(api.get<{ data: StatementImport[] }>(base)),
  get: (id: Id) => unwrap(api.get<{ data: StatementImport }>(`${base}/${id}`)),
  /** Semua baris (mutasi sebulan biasanya < 500 baris; halaman berikutnya diambil berurutan). */
  rows: async (id: Id): Promise<ImportRow[]> => {
    const rows: ImportRow[] = [];
    for (let page = 1; ; page++) {
      const { data } = await api.get<{ data: ImportRow[]; meta: { last_page: number } }>(`${base}/${id}/rows`, { params: { per_page: 500, page } });
      rows.push(...data.data);
      if (page >= data.meta.last_page) return rows;
    }
  },
  /** `column_mapping` null → server memakai pemetaan terakhir akun ini (preset). */
  upload: (body: { account_id: Id; file: File; column_mapping: ColumnMapping | null }) => {
    const form = new FormData();
    form.append('account_id', String(body.account_id));
    form.append('format', 'generic');
    form.append('file', body.file);
    if (body.column_mapping) form.append('column_mapping', JSON.stringify(body.column_mapping));
    return unwrap(api.post<{ data: StatementImport }>(base, form));
  },
  commit: async (id: Id, rows: { id: Id; category_id: Id | null }[]): Promise<CommitResult> => {
    const { data } = await api.post<{ data: StatementImport; meta: CommitResult['meta'] }>(`${base}/${id}/commit`, { rows });
    return { import: data.data, meta: data.meta };
  },
};
