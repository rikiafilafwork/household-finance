import { Archive, Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { Category, CategoryKind } from '@/api/types';
import { CategoryForm } from '@/components/categories/CategoryForm';
import { CategoryIcon } from '@/components/categories/categoryMeta';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCategoryMutations } from '@/hooks/useAccountMutations';
import { useCategories } from '@/hooks/useMoneyData';

type DialogState = { mode: 'new' } | { mode: 'edit'; category: Category } | null;

const kindLabel: Record<CategoryKind, string> = { expense: 'Pengeluaran', income: 'Pemasukan' };
const byName = (a: Category, b: Category) => a.name.localeCompare(b.name, 'id');

function CategoryList({ categories, onEdit, muted }: { categories: Category[]; onEdit: (c: Category) => void; muted?: boolean }) {
  return (
    <ul className={muted ? 'divide-y rounded-lg border bg-background opacity-80' : 'divide-y rounded-lg border bg-background'}>
      {categories.map((c) => (
        <li key={c.id}>
          <button type="button" onClick={() => onEdit(c)} className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-muted/50 focus-visible:bg-muted focus-visible:outline-none">
            <CategoryIcon category={c} />
            <span className="truncate text-sm font-medium">{c.name}</span>
          </button>
        </li>
      ))}
    </ul>
  );
}

/** Pengaturan → Kategori. Kategori diarsip hilang dari pilihan baru tapi tetap bernama di transaksi lama. */
export function CategoriesPage() {
  const { data: categories, isPending } = useCategories();
  const m = useCategoryMutations();
  const [kind, setKind] = useState<CategoryKind>('expense');
  const [dialog, setDialog] = useState<DialogState>(null);
  const [showArchived, setShowArchived] = useState(false);

  const ofKind = (categories ?? []).filter((c) => c.kind === kind);
  const active = ofKind.filter((c) => !c.archived_at).sort(byName);
  const archived = ofKind.filter((c) => c.archived_at).sort(byName);
  const close = () => setDialog(null);
  const editing = dialog?.mode === 'edit' ? dialog.category : undefined;

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <Tabs value={kind} onValueChange={(v) => { setKind(v as CategoryKind); setShowArchived(false); }}>
          <TabsList>
            <TabsTrigger value="expense">{kindLabel.expense}</TabsTrigger>
            <TabsTrigger value="income">{kindLabel.income}</TabsTrigger>
          </TabsList>
        </Tabs>
        <Button onClick={() => setDialog({ mode: 'new' })}><Plus /> Tambah kategori</Button>
      </div>

      {isPending && <Skeleton className="h-40" />}
      {!isPending && active.length === 0 && (
        <p className="rounded-lg border border-dashed bg-background p-8 text-center text-sm text-muted-foreground">Belum ada kategori {kindLabel[kind].toLowerCase()} aktif.</p>
      )}
      {active.length > 0 && <CategoryList categories={active} onEdit={(c) => setDialog({ mode: 'edit', category: c })} />}

      {archived.length > 0 && (
        <div className="mt-6 grid gap-2">
          <Button variant="ghost" size="sm" className="justify-self-start" onClick={() => setShowArchived((v) => !v)} aria-expanded={showArchived}>
            <Archive /> {showArchived ? 'Sembunyikan' : 'Tampilkan'} kategori diarsip ({archived.length})
          </Button>
          {showArchived && <CategoryList muted categories={archived} onEdit={(c) => setDialog({ mode: 'edit', category: c })} />}
        </div>
      )}

      <Dialog open={dialog !== null} onOpenChange={(open) => !open && close()}>
        <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{editing ? 'Ubah kategori' : `Kategori ${kindLabel[kind].toLowerCase()} baru`}</DialogTitle>
            <DialogDescription className="sr-only">Nama, warna, dan ikon kategori</DialogDescription>
          </DialogHeader>
          {dialog && (
            <CategoryForm
              key={editing?.id ?? 'new'}
              kind={editing?.kind ?? kind}
              initial={editing}
              onSubmit={async (payload) => {
                if (editing) await m.update.mutateAsync({ id: editing.id, body: payload });
                else await m.create.mutateAsync({ kind, ...payload });
                toast.success(editing ? 'Kategori diperbarui.' : 'Kategori ditambahkan.');
                close();
              }}
              onArchive={editing ? async () => {
                await m.archive.mutateAsync({ id: editing.id, archived: !editing.archived_at });
                toast.success(editing.archived_at ? 'Kategori aktif lagi.' : 'Kategori diarsip. Transaksi lamanya tetap tampil.');
                close();
              } : undefined}
              onDelete={editing ? async () => { await m.remove.mutateAsync(editing.id); toast.success('Kategori dihapus.'); close(); } : undefined}
            />
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
