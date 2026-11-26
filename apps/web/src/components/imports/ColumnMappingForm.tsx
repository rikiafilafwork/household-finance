import { DATE_FORMATS, type ColumnMapping, type ColumnRef, type DateFormat } from '@/api/imports';
import { Field } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { guessDateFormat, guessDecimalSeparator } from '@/lib/csv-preview';
import { cn } from '@/lib/utils';
import { DATE_FORMAT_EXAMPLE } from './importMeta';

/** Isian form pemetaan; kolom = indeks di record (diubah ke nama header saat dikirim). */
export interface MappingDraft {
  hasHeader: boolean;
  skipRows: number;
  date: number | null;
  dateFormat: DateFormat;
  description: number[];
  amountMode: 'single' | 'debit_credit';
  amount: number | null;
  debit: number | null;
  credit: number | null;
  decimal: ',' | '.';
}

export const EMPTY_DRAFT: MappingDraft = {
  hasHeader: true, skipRows: 0, date: null, dateFormat: 'd/m/Y', description: [],
  amountMode: 'single', amount: null, debit: null, credit: null, decimal: ',',
};

const find = (header: string[], pattern: RegExp, exclude: (number | null)[] = []) => {
  const index = header.findIndex((name, i) => pattern.test(name) && !exclude.includes(i));
  return index === -1 ? null : index;
};

/**
 * Tebakan awal dari nama header + isi kolom (Tanggal/Keterangan/Debet/Kredit/Jumlah). User tetap
 * bisa mengubahnya; server yang memvalidasi.
 */
export function guessDraft(records: string[][], hasHeader: boolean, skipRows: number): MappingDraft {
  const header = hasHeader ? (records[skipRows] ?? []).map((h) => h.toLowerCase()) : [];
  const data = records.slice(skipRows + (hasHeader ? 1 : 0), skipRows + (hasHeader ? 1 : 0) + 20);
  const column = (i: number | null) => (i === null ? [] : data.map((r) => r[i] ?? ''));

  const date = find(header, /tanggal|tgl|date/);
  const debit = find(header, /debe?t|debit|keluar|\bdb\b/);
  const credit = find(header, /kredit|credit|masuk|\bcr\b/);
  const amount = find(header, /jumlah|nominal|amount|mutasi/, [debit, credit]);
  const description = find(header, /keterangan|\bket\b|deskripsi|uraian|description|remark|berita/);
  const amountMode = debit !== null && credit !== null && amount === null ? 'debit_credit' : 'single';
  const amountValues = amountMode === 'single' ? column(amount) : [...column(debit), ...column(credit)];

  return {
    hasHeader, skipRows,
    date,
    dateFormat: guessDateFormat(column(date)) ?? 'd/m/Y',
    description: description === null ? [] : [description],
    amountMode,
    amount,
    debit: amountMode === 'debit_credit' ? debit : null,
    credit: amountMode === 'debit_credit' ? credit : null,
    decimal: guessDecimalSeparator(amountValues.filter(Boolean)),
  };
}

/** Draft → bentuk API. Error pertama yang menghalangi pengiriman, atau mapping-nya. */
export function toMapping(draft: MappingDraft, header: string[] | null): { error: string } | { mapping: ColumnMapping } {
  const ref = (i: number): ColumnRef => (draft.hasHeader && header ? header[i] ?? i : i);

  if (draft.date === null) return { error: 'Pilih kolom tanggal.' };
  if (draft.description.length === 0) return { error: 'Pilih minimal satu kolom keterangan.' };
  if (draft.amountMode === 'single' && draft.amount === null) return { error: 'Pilih kolom nominal.' };
  if (draft.amountMode === 'debit_credit' && (draft.debit === null || draft.credit === null)) return { error: 'Pilih kolom debet dan kredit.' };
  if (draft.hasHeader && header && [draft.date, ...draft.description, draft.amount, draft.debit, draft.credit].some((i) => i !== null && !header[i])) {
    return { error: 'Kolom yang dipilih tidak punya nama header. Matikan "Baris pertama berisi judul kolom" atau pilih kolom lain.' };
  }

  return {
    mapping: {
      has_header: draft.hasHeader,
      skip_rows: draft.skipRows,
      date: ref(draft.date),
      date_format: draft.dateFormat,
      description: draft.description.map(ref),
      ...(draft.amountMode === 'single'
        ? { amount: ref(draft.amount as number) }
        : { debit: ref(draft.debit as number), credit: ref(draft.credit as number) }),
      decimal_separator: draft.decimal,
    },
  };
}

function ColumnSelect({ id, label, value, columns, onChange }: {
  id: string; label: string; value: number | null; columns: string[]; onChange: (value: number | null) => void;
}) {
  return (
    <Field label={label} htmlFor={id}>
      <NativeSelect id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}>
        <option value="">— pilih kolom —</option>
        {columns.map((name, i) => <option key={i} value={i}>{name}</option>)}
      </NativeSelect>
    </Field>
  );
}

/** Pemetaan kolom file → tanggal / keterangan / nominal, dengan cuplikan isi file. */
export function ColumnMappingForm({ records, draft, onChange }: {
  records: string[][];
  draft: MappingDraft;
  onChange: (draft: MappingDraft) => void;
}) {
  const width = Math.max(0, ...records.map((r) => r.length));
  const header = draft.hasHeader ? records[draft.skipRows] ?? [] : null;
  const columns = Array.from({ length: width }, (_, i) => (header?.[i] ? header[i] : `Kolom ${i + 1}`));
  const sample = records.slice(draft.skipRows + (draft.hasHeader ? 1 : 0)).slice(0, 5);
  const mapped = new Set([draft.date, ...draft.description, draft.amount, draft.debit, draft.credit].filter((i): i is number => i !== null));
  const set = (patch: Partial<MappingDraft>) => onChange({ ...draft, ...patch });
  const regroup = (patch: Partial<MappingDraft>) => onChange(guessDraft(records, patch.hasHeader ?? draft.hasHeader, patch.skipRows ?? draft.skipRows));

  return (
    <div className="grid gap-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Baris dilewati di atas tabel" htmlFor="map-skip" hint="Baris info rekening/periode di awal file.">
          <Input
            id="map-skip" type="number" min={0} max={50} value={draft.skipRows}
            onChange={(e) => regroup({ skipRows: Math.min(50, Math.max(0, Number(e.target.value) || 0)) })}
          />
        </Field>
        <label className="flex items-center gap-2 self-end pb-2 text-sm">
          <input type="checkbox" className="size-4" checked={draft.hasHeader} onChange={(e) => regroup({ hasHeader: e.target.checked })} />
          Baris pertama berisi judul kolom
        </label>
      </div>

      <div className="overflow-x-auto rounded-md border">
        <Table aria-label="Cuplikan file">
          <TableHeader>
            <TableRow>
              {columns.map((name, i) => (
                <TableHead key={i} className={cn('whitespace-nowrap', mapped.has(i) && 'bg-primary/10 text-primary')}>{name}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {sample.map((record, r) => (
              <TableRow key={r}>
                {columns.map((_, i) => <TableCell key={i} className="max-w-56 truncate whitespace-nowrap text-xs">{record[i] ?? ''}</TableCell>)}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <ColumnSelect id="map-date" label="Kolom tanggal" value={draft.date} columns={columns} onChange={(date) => set({ date })} />
        <Field label="Format tanggal" htmlFor="map-date-format">
          <NativeSelect id="map-date-format" value={draft.dateFormat} onChange={(e) => set({ dateFormat: e.target.value as DateFormat })}>
            {DATE_FORMATS.map((f) => <option key={f} value={f}>{DATE_FORMAT_EXAMPLE[f]}</option>)}
          </NativeSelect>
        </Field>
      </div>

      <fieldset className="grid gap-2">
        <legend className="mb-1 text-sm font-medium">Kolom keterangan</legend>
        <div className="flex flex-wrap gap-x-4 gap-y-2">
          {columns.map((name, i) => (
            <label key={i} className="flex items-center gap-2 text-sm">
              <input
                type="checkbox" className="size-4" checked={draft.description.includes(i)}
                onChange={(e) => set({ description: e.target.checked ? [...draft.description, i].sort((a, b) => a - b) : draft.description.filter((d) => d !== i) })}
              />
              {name}
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset className="grid gap-3">
        <legend className="mb-1 text-sm font-medium">Nominal</legend>
        <div className="flex flex-wrap gap-4 text-sm">
          <label className="flex items-center gap-2">
            <input type="radio" name="amount-mode" checked={draft.amountMode === 'single'} onChange={() => set({ amountMode: 'single', debit: null, credit: null })} />
            Satu kolom (minus / akhiran DB-CR)
          </label>
          <label className="flex items-center gap-2">
            <input type="radio" name="amount-mode" checked={draft.amountMode === 'debit_credit'} onChange={() => set({ amountMode: 'debit_credit', amount: null })} />
            Kolom debet dan kredit terpisah
          </label>
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          {draft.amountMode === 'single' ? (
            <ColumnSelect id="map-amount" label="Kolom nominal" value={draft.amount} columns={columns} onChange={(amount) => set({ amount })} />
          ) : (
            <>
              <ColumnSelect id="map-debit" label="Kolom debet (keluar)" value={draft.debit} columns={columns} onChange={(debit) => set({ debit })} />
              <ColumnSelect id="map-credit" label="Kolom kredit (masuk)" value={draft.credit} columns={columns} onChange={(credit) => set({ credit })} />
            </>
          )}
          <Field label="Pemisah desimal" htmlFor="map-decimal">
            <NativeSelect id="map-decimal" value={draft.decimal} onChange={(e) => set({ decimal: e.target.value as ',' | '.' })}>
              <option value=",">Koma — 1.250.000,00</option>
              <option value=".">Titik — 1,250,000.00</option>
            </NativeSelect>
          </Field>
        </div>
      </fieldset>
    </div>
  );
}
