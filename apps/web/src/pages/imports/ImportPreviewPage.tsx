import { AlertTriangle, CheckCircle2, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router';
import { ApiError } from '@/api/client';
import { PROCESSING, type ImportRow, type StatementImport } from '@/api/imports';
import type { Category, Id } from '@/api/types';
import { FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { IMPORT_STATUS_LABEL, ImportStatusBadge, RowStatusBadge } from '@/components/imports/importMeta';
import { PageHeader } from '@/components/layout/PageHeader';
import { MoneyText } from '@/components/money/MoneyText';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useImport, useImportMutations, useImportRows } from '@/hooks/useImports';
import { useAccounts, useCategories } from '@/hooks/useMoneyData';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';

const SELECTABLE = new Set(['new', 'possible_duplicate']);

function SummaryCards({ imp }: { imp: StatementImport }) {
  const c = imp.counts;
  const cards = imp.status === 'committed'
    ? [['Diimpor', c.committed], ['Sudah ada', c.duplicate], ['Dilewati', c.skipped], ['Tidak terbaca', c.invalid]]
    : [['Baru', c.new], ['Mungkin dobel', c.possible_duplicate], ['Sudah ada', c.duplicate], ['Tidak terbaca', c.invalid]];

  return (
    <dl className="grid grid-cols-2 gap-3 md:grid-cols-4" aria-label="Ringkasan">
      {cards.map(([label, value]) => (
        <Card key={label} className="py-3">
          <CardContent className="px-4">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-xl font-semibold tabular-nums">{value}</dd>
          </CardContent>
        </Card>
      ))}
    </dl>
  );
}

function CategoryPicker({ row, categories, value, disabled, onChange }: {
  row: ImportRow; categories: Category[]; value: Id | null; disabled: boolean; onChange: (id: Id | null) => void;
}) {
  const kind = (row.amount ?? 0) > 0 ? 'income' : 'expense';
  const options = categories.filter((c) => c.kind === kind && !c.archived_at);
  return (
    <NativeSelect
      aria-label={`Kategori baris ${row.row_number}`}
      className="h-8 min-w-36 text-xs"
      value={value ?? ''}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
    >
      <option value="">Tanpa kategori</option>
      {options.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
    </NativeSelect>
  );
}

/**
 * Impor langkah 2–3: tunggu file selesai dibaca, tinjau baris (centang + kategori), impor.
 * Baris baru tercentang otomatis; "mungkin dobel" (mirip transaksi yang dicatat manual) tidak.
 */
export function ImportPreviewPage() {
  const id = Number(useParams().id);
  const { data: imp, isPending, error } = useImport(id);
  const ready = imp?.status === 'ready' || imp?.status === 'committing';
  const done = imp?.status === 'committed';
  const { data: rows = [], isPending: rowsPending } = useImportRows(id, ready || done);
  const { data: categories = [] } = useCategories();
  const { data: accounts = [] } = useAccounts();
  const { commit } = useImportMutations();

  // Pilihan user; baris yang belum disentuh mengikuti default (baru = dicentang).
  const [picked, setPicked] = useState<Record<Id, boolean>>({});
  const [categoryOf, setCategoryOf] = useState<Record<Id, Id | null>>({});
  const [result, setResult] = useState<{ committed: number; duplicate: number; skipped: number } | null>(null);
  const [commitError, setCommitError] = useState<string | null>(null);

  const isPicked = (row: ImportRow) => SELECTABLE.has(row.status) && (picked[row.id] ?? row.status === 'new');
  const selectable = useMemo(() => rows.filter((r) => SELECTABLE.has(r.status)), [rows]);
  const selected = selectable.filter(isPicked);
  const allPicked = selectable.length > 0 && selected.length === selectable.length;
  const total = selected.reduce((sum, r) => sum + (r.amount ?? 0), 0);

  async function submit() {
    setCommitError(null);
    try {
      const { meta } = await commit.mutateAsync({ id, rows: selected.map((r) => ({ id: r.id, category_id: categoryOf[r.id] ?? null })) });
      setResult(meta);
    } catch (e) {
      if (e instanceof ApiError && e.isValidation) setCommitError(Object.values(e.fieldErrors)[0]?.[0] ?? e.message);
    }
  }

  if (isPending) return <Skeleton className="h-40 w-full" />;
  if (error || !imp) {
    return (
      <>
        <PageHeader title="Impor mutasi" />
        <p className="text-sm text-muted-foreground">Impor tidak ditemukan. <Link to="/imports" className="text-primary underline">Kembali ke daftar impor</Link></p>
      </>
    );
  }

  const accountName = accounts.find((a) => a.id === imp.account_id)?.name;
  const categoryName = (cid: Id | null) => categories.find((c) => c.id === cid)?.name ?? '—';

  return (
    <>
      <PageHeader
        title={imp.original_filename}
        description={<>{accountName ?? 'Akun rekening'} · diunggah {formatDate(imp.created_at.slice(0, 10))}</>}
        actions={<ImportStatusBadge status={imp.status} />}
      />

      {PROCESSING.includes(imp.status) && (
        <Card><CardContent className="flex items-center gap-3 py-6 text-sm" role="status">
          <Loader2 className="size-5 animate-spin" aria-hidden /> {IMPORT_STATUS_LABEL[imp.status]}… biasanya hanya beberapa detik.
        </CardContent></Card>
      )}

      {imp.status === 'failed' && (
        <Card><CardContent className="grid gap-3 py-6 text-sm" role="alert">
          <p className="flex items-center gap-2 font-medium text-destructive"><AlertTriangle className="size-4" /> File tidak bisa dibaca</p>
          <p>{imp.error}</p>
          <p><Link to="/imports" className="text-primary underline">Unggah ulang dengan pemetaan kolom lain</Link></p>
        </CardContent></Card>
      )}

      {(ready || done) && (
        <div className="grid gap-4">
          <SummaryCards imp={imp} />

          {(done || result) && (
            <div role="status" className="flex flex-wrap items-center gap-2 rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm">
              <CheckCircle2 className="size-4 text-success" aria-hidden />
              {result
                ? `${result.committed} transaksi diimpor${result.duplicate ? `, ${result.duplicate} ternyata sudah ada` : ''}${result.skipped ? `, ${result.skipped} dilewati` : ''}.`
                : `${imp.counts.committed} transaksi diimpor.`}
              <Link to="/transactions" className="ml-auto text-primary underline">Lihat transaksi</Link>
            </div>
          )}

          {ready && !rowsPending && selectable.length === 0 && (
            // Semua baris sudah ada / tidak terbaca (mis. file yang sama diunggah ulang): tidak ada
            // yang diimpor, tapi import tetap bisa ditutup supaya filenya dihapus dari server.
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-muted-foreground">Tidak ada baris baru — semua transaksi di file ini sudah tercatat.</p>
              <Button variant="outline" onClick={() => void submit()} disabled={commit.isPending}>
                {commit.isPending ? 'Menutup…' : 'Tandai selesai'}
              </Button>
            </div>
          )}
          {ready && !rowsPending && selectable.length > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-muted-foreground">
                {selected.length} dari {selectable.length} baris dipilih · total <MoneyText amount={total} tone="signed" />
              </p>
              <Button onClick={() => void submit()} disabled={selected.length === 0 || commit.isPending}>
                {commit.isPending ? 'Mengimpor…' : `Impor ${selected.length} transaksi`}
              </Button>
            </div>
          )}
          <FormMessage message={commitError} />

          {rowsPending ? (
            <Skeleton className="h-48 w-full" />
          ) : (
            <div className="overflow-x-auto rounded-md border bg-background">
              <Table aria-label="Baris mutasi">
                <TableHeader>
                  <TableRow>
                    {ready && (
                      <TableHead className="w-8">
                        <input
                          type="checkbox" className="size-4" aria-label="Pilih semua baris yang bisa diimpor"
                          checked={allPicked} disabled={selectable.length === 0}
                          onChange={(e) => setPicked(Object.fromEntries(selectable.map((r) => [r.id, e.target.checked])))}
                        />
                      </TableHead>
                    )}
                    <TableHead>Tanggal</TableHead>
                    <TableHead>Keterangan</TableHead>
                    <TableHead className="text-right">Nominal</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Kategori</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => {
                    const canPick = ready && SELECTABLE.has(row.status);
                    return (
                      <TableRow key={row.id} data-testid={`row-${row.row_number}`} className={cn(!canPick && !done && 'text-muted-foreground')}>
                        {ready && (
                          <TableCell>
                            {canPick && (
                              <input
                                type="checkbox" className="size-4" aria-label={`Impor baris ${row.row_number}`}
                                checked={isPicked(row)} onChange={(e) => setPicked({ ...picked, [row.id]: e.target.checked })}
                              />
                            )}
                          </TableCell>
                        )}
                        <TableCell className="whitespace-nowrap">{row.occurred_on ? formatDate(row.occurred_on) : <span className="text-xs">baris {row.row_number}</span>}</TableCell>
                        <TableCell className="max-w-80">
                          <span className="line-clamp-2 text-sm">{row.description ?? row.raw.join(' · ')}</span>
                          {row.error && <span className="block text-xs text-destructive">{row.error}</span>}
                        </TableCell>
                        <TableCell className="text-right">{row.amount !== null && <MoneyText amount={row.amount} tone="signed" />}</TableCell>
                        <TableCell><RowStatusBadge status={row.status} /></TableCell>
                        <TableCell>
                          {canPick ? (
                            <CategoryPicker
                              row={row} categories={categories} value={categoryOf[row.id] ?? null} disabled={!isPicked(row)}
                              onChange={(cid) => setCategoryOf({ ...categoryOf, [row.id]: cid })}
                            />
                          ) : (
                            <span className="text-xs">{row.status === 'committed' ? categoryName(row.category_id) : ''}</span>
                          )}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          )}
        </div>
      )}
    </>
  );
}
