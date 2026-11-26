import type { ImportRowStatus, ImportStatus } from '@/api/imports';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export const IMPORT_STATUS_LABEL: Record<ImportStatus, string> = {
  pending: 'Menunggu diproses',
  parsing: 'Sedang dibaca',
  ready: 'Siap ditinjau',
  committing: 'Sedang diimpor',
  committed: 'Selesai',
  failed: 'Gagal',
};

export const ROW_STATUS_LABEL: Record<ImportRowStatus, string> = {
  new: 'Baru',
  duplicate: 'Sudah ada',
  possible_duplicate: 'Mungkin dobel',
  invalid: 'Tidak terbaca',
  committed: 'Diimpor',
  skipped: 'Dilewati',
};

const ROW_STATUS_CLASS: Record<ImportRowStatus, string> = {
  new: 'bg-primary/10 text-primary',
  duplicate: 'bg-muted text-muted-foreground',
  possible_duplicate: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-400',
  invalid: 'bg-destructive/10 text-destructive',
  committed: 'bg-success/10 text-success',
  skipped: 'bg-muted text-muted-foreground',
};

export function RowStatusBadge({ status }: { status: ImportRowStatus }) {
  return <Badge variant="secondary" className={cn(ROW_STATUS_CLASS[status])}>{ROW_STATUS_LABEL[status]}</Badge>;
}

export function ImportStatusBadge({ status }: { status: ImportStatus }) {
  return (
    <Badge
      variant={status === 'failed' ? 'destructive' : status === 'committed' ? 'secondary' : 'outline'}
      className={cn(status === 'committed' && 'bg-success/10 text-success')}
    >
      {IMPORT_STATUS_LABEL[status]}
    </Badge>
  );
}

/** Contoh di form pemetaan: `d/m/Y` → `31/12/2026`. */
export const DATE_FORMAT_EXAMPLE: Record<string, string> = {
  'd/m/Y': '31/12/2026', 'd-m-Y': '31-12-2026', 'd.m.Y': '31.12.2026', 'Y-m-d': '2026-12-31', 'Y/m/d': '2026/12/31',
  'd/m/y': '31/12/26', 'd-m-y': '31-12-26', 'm/d/Y': '12/31/2026 (bulan dulu)',
  'd M Y': '31 Des 2026', 'd-M-Y': '31-Des-2026', 'd M y': '31 Des 26', 'd-M-y': '31-Des-26',
  'd/m': '31/12 (tanpa tahun)', 'd-m': '31-12 (tanpa tahun)', 'd M': '31 Des (tanpa tahun)',
};
