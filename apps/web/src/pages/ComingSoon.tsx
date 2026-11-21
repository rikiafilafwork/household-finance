import { PageHeader } from '@/components/layout/PageHeader';

/** Penanda halaman yang menyusul di issue berikutnya Minggu 4. */
export function ComingSoon({ title, issue }: { title: string; issue: number }) {
  return (
    <>
      <PageHeader title={title} />
      <div className="rounded-lg border border-dashed bg-background p-10 text-center text-sm text-muted-foreground">
        Halaman ini sedang dibuat (#{issue}).
      </div>
    </>
  );
}
