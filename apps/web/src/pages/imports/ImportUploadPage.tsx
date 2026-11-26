import { FileUp } from 'lucide-react';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { ApiError } from '@/api/client';
import type { StatementImport } from '@/api/imports';
import type { Account } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { ColumnMappingForm, EMPTY_DRAFT, guessDraft, toMapping, type MappingDraft } from '@/components/imports/ColumnMappingForm';
import { ImportStatusBadge } from '@/components/imports/importMeta';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useImportMutations, useImports } from '@/hooks/useImports';
import { useAccounts } from '@/hooks/useMoneyData';
import { detectDelimiter, parseCsv, readHead } from '@/lib/csv-preview';
import { formatDate } from '@/lib/dates';

/** Ringkasan pemetaan tersimpan, mis. "Tanggal · Keterangan · Debet/Kredit". */
function describeMapping(imp: StatementImport): string {
  const m = imp.column_mapping;
  if (!m) return '';
  const name = (c: string | number) => (typeof c === 'number' ? `Kolom ${c + 1}` : c);
  const amount = m.amount !== undefined ? name(m.amount) : `${name(m.debit ?? '')}/${name(m.credit ?? '')}`;
  return [name(m.date), m.description.map(name).join(' + '), amount].join(' · ');
}

function ImportHistory({ imports, accounts }: { imports: StatementImport[]; accounts: Account[] }) {
  if (imports.length === 0) return null;
  const accountName = (id: number) => accounts.find((a) => a.id === id)?.name ?? 'Akun';

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Riwayat impor</CardTitle>
      </CardHeader>
      <CardContent>
        <ul className="divide-y" aria-label="Riwayat impor">
          {imports.map((imp) => (
            <li key={imp.id}>
              <Link to={`/imports/${imp.id}`} className="flex flex-wrap items-center justify-between gap-2 py-2.5 hover:bg-accent/50">
                <span className="grid min-w-0">
                  <span className="truncate text-sm font-medium">{imp.original_filename}</span>
                  <span className="text-xs text-muted-foreground">
                    {accountName(imp.account_id)} · {formatDate(imp.created_at.slice(0, 10))}
                    {imp.status === 'committed' && ` · ${imp.counts.committed} transaksi diimpor`}
                    {imp.status === 'ready' && ` · ${imp.counts.new} baris baru`}
                  </span>
                </span>
                <ImportStatusBadge status={imp.status} />
              </Link>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

/**
 * Impor langkah 1: pilih akun rekening + file CSV, petakan kolom (atau pakai pemetaan terakhir
 * akun itu), unggah. Server mem-parse di queue; halaman preview mem-poll statusnya.
 */
export function ImportUploadPage() {
  const navigate = useNavigate();
  const { data: accounts, isPending } = useAccounts();
  const { data: imports = [] } = useImports();
  const { upload } = useImportMutations();

  const banks = (accounts ?? []).filter((a) => a.type === 'bank' && !a.archived_at);
  const [accountId, setAccountId] = useState<number | null>(null);
  const account = accountId ?? banks[0]?.id ?? null;

  const [file, setFile] = useState<File | null>(null);
  const [records, setRecords] = useState<string[][]>([]);
  const [draft, setDraft] = useState<MappingDraft>(EMPTY_DRAFT);
  const [usePreset, setUsePreset] = useState(true);
  const [error, setError] = useState<{ field?: string; message: string } | null>(null);

  const preset = imports.find((imp) => imp.account_id === account && imp.column_mapping !== null) ?? null;
  const presetActive = preset !== null && usePreset;

  async function pickFile(picked: File | null) {
    setFile(picked);
    setError(null);
    if (!picked) {
      setRecords([]);
      return;
    }
    const head = await readHead(picked);
    const parsed = parseCsv(head, detectDelimiter(head));
    setRecords(parsed);
    setDraft(guessDraft(parsed, true, 0));
  }

  async function submit() {
    setError(null);
    if (account === null) return setError({ field: 'account_id', message: 'Pilih akun rekening.' });
    if (!file) return setError({ field: 'file', message: 'Pilih file CSV mutasi.' });

    let mapping = null;
    if (!presetActive) {
      const result = toMapping(draft, draft.hasHeader ? records[draft.skipRows] ?? [] : null);
      if ('error' in result) return setError({ field: 'column_mapping', message: result.error });
      mapping = result.mapping;
    }

    try {
      const created = await upload.mutateAsync({ account_id: account, file, column_mapping: mapping });
      void navigate(`/imports/${created.id}`);
    } catch (e) {
      if (e instanceof ApiError && e.isValidation) {
        const [field, messages] = Object.entries(e.fieldErrors)[0] ?? [];
        setError({ field, message: messages?.[0] ?? e.message });
      } else if (e instanceof ApiError && e.status === 404) {
        setError({ field: 'account_id', message: e.message });
      }
    }
  }

  const fieldError = (field: string) => (error?.field === field ? error.message : undefined);
  const generalError = error && !['account_id', 'file', 'column_mapping'].includes(error.field ?? '') ? error.message : null;

  return (
    <>
      <PageHeader title="Impor mutasi" description="Unggah CSV mutasi rekening, tinjau, lalu impor jadi transaksi. File yang sama tidak akan masuk dua kali." />

      <div className="grid gap-4 md:gap-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Unggah file</CardTitle>
            <CardDescription>Hanya untuk akun rekening (bank). Unduh mutasi dalam format CSV dari internet banking.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-4">
            {isPending ? (
              <Skeleton className="h-10 w-full" />
            ) : banks.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                Belum ada akun rekening. <Link to="/accounts" className="text-primary underline">Tambahkan akun bank</Link> dulu.
              </p>
            ) : (
              <>
                <FormMessage message={generalError} />
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Akun rekening" htmlFor="import-account" error={fieldError('account_id')}>
                    <NativeSelect id="import-account" value={account ?? ''} onChange={(e) => setAccountId(Number(e.target.value))}>
                      {banks.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                    </NativeSelect>
                  </Field>
                  <Field label="File CSV" htmlFor="import-file" error={fieldError('file')} hint="Maksimal 5 MB, .csv atau .txt.">
                    <Input id="import-file" type="file" accept=".csv,.txt,text/csv,text/plain" onChange={(e) => void pickFile(e.target.files?.[0] ?? null)} />
                  </Field>
                </div>

                {preset && (
                  <label className="flex items-start gap-2 rounded-md border bg-muted/40 p-3 text-sm">
                    <input type="checkbox" className="mt-0.5 size-4" checked={usePreset} onChange={(e) => setUsePreset(e.target.checked)} />
                    <span>
                      Pakai pemetaan kolom terakhir akun ini
                      <span className="block text-xs text-muted-foreground">{describeMapping(preset)}</span>
                    </span>
                  </label>
                )}

                {file && !presetActive && records.length > 0 && <ColumnMappingForm records={records} draft={draft} onChange={setDraft} />}
                {fieldError('column_mapping') && <p role="alert" className="text-sm text-destructive">{fieldError('column_mapping')}</p>}

                <div>
                  <Button onClick={() => void submit()} disabled={upload.isPending}>
                    <FileUp className="size-4" /> {upload.isPending ? 'Mengunggah…' : 'Unggah & tinjau'}
                  </Button>
                </div>
              </>
            )}
          </CardContent>
        </Card>

        <ImportHistory imports={imports} accounts={accounts ?? []} />
      </div>
    </>
  );
}
