import { PageHeader } from '@/components/layout/PageHeader';

/**
 * Penanda halaman yang menyusul di issue berikutnya.
 * `bare`: tanpa judul halaman (dipakai di dalam layout Pengaturan yang sudah berjudul).
 */
export function ComingSoon({ title, issue, bare = false }: { title: string; issue: number; bare?: boolean }) {
  return (
    <>
      {!bare && <PageHeader title={title} />}
      <div className="rounded-lg border border-dashed bg-background p-10 text-center text-sm text-muted-foreground">
        {bare ? `${title}: ` : ''}Halaman ini sedang dibuat (#{issue}).
      </div>
    </>
  );
}
