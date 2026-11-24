import { EllipsisVertical, Plus, Repeat, Zap } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { toast } from 'sonner';
import type { PlanItemType, PlanMode } from '@/api/plan';
import type { PlanTemplate } from '@/api/templates';
import type { Account, Category } from '@/api/types';
import { CategoryIcon } from '@/components/categories/categoryMeta';
import { MoneyText } from '@/components/money/MoneyText';
import { TemplateForm } from '@/components/plan/TemplateForm';
import { scheduleLabel } from '@/components/plan/templateMeta';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { useAccounts, useCategories } from '@/hooks/useMoneyData';
import { useTemplateMutations, useTemplates } from '@/hooks/useTemplateMutations';
import { cn } from '@/lib/utils';

type DialogState =
  | { mode: 'new'; preset: { type: PlanItemType; mode: PlanMode } }
  | { mode: 'edit' | 'delete'; template: PlanTemplate }
  | null;

function TemplateRow({ template, category, account, onEdit, onToggle, onDelete }: {
  template: PlanTemplate;
  category?: Category;
  account?: Account;
  onEdit: () => void;
  onToggle: () => void;
  onDelete: () => void;
}) {
  const meta = [category?.name, scheduleLabel(template), template.mode === 'checklist' ? account?.name : null].filter(Boolean).join(' · ');
  return (
    <li data-testid={`template-${template.id}`} className={cn('flex items-center gap-3 py-3', !template.is_active && 'opacity-60')}>
      {category ? <CategoryIcon category={category} className="size-6" /> : <span className="size-6" />}
      <button type="button" onClick={onEdit} className="grid min-w-0 flex-1 gap-0.5 text-left">
        <span className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
          <span className="truncate">{template.name}</span>
          {template.auto_post && <Badge variant="secondary"><Zap /> Auto-post</Badge>}
          {template.frequency === 'yearly' && <Badge variant="outline">Tahunan</Badge>}
          {!template.is_active && <Badge variant="outline">Nonaktif</Badge>}
        </span>
        <span className="truncate text-xs text-muted-foreground">{meta}</span>
      </button>
      <MoneyText amount={template.planned_amount} className="text-sm font-medium" />
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8 shrink-0" aria-label={`Aksi ${template.name}`}><EllipsisVertical className="size-4" /></Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem onSelect={onEdit}>Ubah</DropdownMenuItem>
          <DropdownMenuItem onSelect={onToggle}>{template.is_active ? 'Nonaktifkan' : 'Aktifkan'}</DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem variant="destructive" onSelect={onDelete}>Hapus</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </li>
  );
}

function Section({ title, children, onAdd, addLabel }: { title: string; children: ReactNode; onAdd: () => void; addLabel: string }) {
  return (
    <section aria-label={title} className="mb-6">
      <h2 className="mb-1 px-1 text-sm font-semibold">{title}</h2>
      <div className="rounded-lg border bg-background px-4">
        <ul className="divide-y">{children}</ul>
        <div className="border-t py-1.5">
          <Button variant="ghost" size="sm" onClick={onAdd} className="text-muted-foreground"><Plus /> {addLabel}</Button>
        </div>
      </div>
    </section>
  );
}

/**
 * Pengaturan → Template bulanan (Keputusan #9). Template aktif mengisi rencana tiap tanggal 1
 * (atau lewat "Buat dari template"); mengubahnya tidak menyentuh bulan yang sudah dibuat.
 */
export function TemplatesPage() {
  const { data: templates, isPending } = useTemplates();
  const { data: accounts = [] } = useAccounts();
  const { data: categories = [] } = useCategories();
  const m = useTemplateMutations();
  const [dialog, setDialog] = useState<DialogState>(null);
  const [showInactive, setShowInactive] = useState(false);
  const close = () => setDialog(null);

  const categoryMap = new Map(categories.map((c) => [c.id, c]));
  const accountMap = new Map(accounts.map((a) => [a.id, a]));
  const all = templates ?? [];
  const active = all.filter((t) => t.is_active);
  const inactive = all.filter((t) => !t.is_active);
  const groups = [
    { title: 'Pemasukan', items: active.filter((t) => t.type === 'income'), preset: { type: 'income', mode: 'checklist' }, addLabel: 'Tambah pemasukan' },
    { title: 'Pengeluaran tetap', items: active.filter((t) => t.type === 'expense' && t.mode === 'checklist'), preset: { type: 'expense', mode: 'checklist' }, addLabel: 'Tambah tagihan / cicilan' },
    { title: 'Amplop', items: active.filter((t) => t.type === 'expense' && t.mode === 'envelope'), preset: { type: 'expense', mode: 'envelope' }, addLabel: 'Tambah amplop' },
  ] as const;

  const toggle = async (t: PlanTemplate) => {
    try {
      await m.update.mutateAsync({ id: t.id, body: { is_active: !t.is_active } });
      toast.success(t.is_active ? `${t.name} dinonaktifkan.` : `${t.name} aktif lagi.`);
    } catch {
      // 422 (mis. amplop bertabrakan) ditampilkan klien API sebagai toast.
    }
  };

  const row = (t: PlanTemplate) => (
    <TemplateRow
      key={t.id}
      template={t}
      category={categoryMap.get(t.category_id)}
      account={t.account_id !== null ? accountMap.get(t.account_id) : undefined}
      onEdit={() => setDialog({ mode: 'edit', template: t })}
      onToggle={() => void toggle(t)}
      onDelete={() => setDialog({ mode: 'delete', template: t })}
    />
  );

  let title = '';
  let body: ReactNode = null;
  if (dialog?.mode === 'new' || dialog?.mode === 'edit') {
    const editing = dialog.mode === 'edit' ? dialog.template : undefined;
    title = editing ? 'Ubah template' : 'Template baru';
    body = (
      <TemplateForm
        key={editing?.id ?? 'new'}
        initial={editing}
        preset={dialog.mode === 'new' ? dialog.preset : undefined}
        accounts={accounts}
        categories={categories}
        onSubmit={async (payload) => {
          if (editing) await m.update.mutateAsync({ id: editing.id, body: payload });
          else await m.create.mutateAsync(payload);
          toast.success(editing ? 'Template disimpan. Berlaku untuk rencana bulan berikutnya.' : 'Template ditambahkan. Muncul di rencana bulan berikutnya.');
          close();
        }}
      />
    );
  } else if (dialog?.mode === 'delete') {
    const t = dialog.template;
    title = `Hapus template ${t.name}?`;
    body = (
      <div className="grid gap-4">
        <p className="text-sm text-muted-foreground">Rencana yang sudah dibuat dari template ini tetap ada. Kalau hanya ingin berhenti sementara, pilih Nonaktifkan.</p>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={close}>Batal</Button>
          <Button
            variant="destructive"
            disabled={m.remove.isPending}
            onClick={async () => {
              try {
                await m.remove.mutateAsync(t.id);
                toast.success('Template dihapus.');
                close();
              } catch {
                // toast global
              }
            }}
          >
            Ya, hapus
          </Button>
        </div>
      </div>
    );
  }

  return (
    <>
      <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-xl text-sm text-muted-foreground">
          Template aktif mengisi rencana otomatis tiap tanggal 1. Untuk bulan yang sudah berjalan, pakai{' '}
          <Link to="/plan" className="font-medium text-primary underline-offset-4 hover:underline"><Repeat className="inline size-3.5" /> Buat dari template</Link> di halaman Rencana.
        </p>
        <Button onClick={() => setDialog({ mode: 'new', preset: { type: 'expense', mode: 'checklist' } })}><Plus /> Tambah template</Button>
      </div>

      {isPending && <Skeleton className="h-48" />}
      {!isPending && groups.map((g) => (
        <Section key={g.title} title={g.title} onAdd={() => setDialog({ mode: 'new', preset: g.preset })} addLabel={g.addLabel}>
          {g.items.length === 0 && <li className="py-3 text-sm text-muted-foreground">Belum ada.</li>}
          {g.items.map(row)}
        </Section>
      ))}

      {inactive.length > 0 && (
        <div className="grid gap-2">
          <Button variant="ghost" size="sm" className="justify-self-start" onClick={() => setShowInactive((v) => !v)} aria-expanded={showInactive}>
            {showInactive ? 'Sembunyikan' : 'Tampilkan'} template nonaktif ({inactive.length})
          </Button>
          {showInactive && <ul aria-label="Template nonaktif" className="divide-y rounded-lg border bg-background px-4">{inactive.map(row)}</ul>}
        </div>
      )}

      <Dialog open={dialog !== null} onOpenChange={(open) => !open && close()}>
        <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription className="sr-only">{title}</DialogDescription>
          </DialogHeader>
          {body}
        </DialogContent>
      </Dialog>
    </>
  );
}
