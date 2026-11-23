import { zodResolver } from '@hookform/resolvers/zod';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import type { Category, CategoryKind } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { applyServerErrors } from '@/lib/form-errors';
import { cn } from '@/lib/utils';
import { CATEGORY_COLORS, CATEGORY_ICONS, CategoryIcon } from './categoryMeta';

const schema = z.object({
  name: z.string().trim().min(1, 'Nama kategori wajib diisi.').max(60, 'Nama maksimal 60 karakter.'),
  color: z.string().regex(/^#[0-9A-Fa-f]{6}$/, 'Warna harus format #RRGGBB.'),
  icon: z.string(),
});
type Values = z.infer<typeof schema>;

export interface CategoryPayload {
  name: string;
  color: string;
  icon: string;
}

export function CategoryForm({ kind, initial, onSubmit, onArchive, onDelete }: {
  kind: CategoryKind;
  initial?: Category;
  onSubmit: (payload: CategoryPayload) => Promise<unknown>;
  onArchive?: () => Promise<unknown>;
  onDelete?: () => Promise<unknown>;
}) {
  const [message, setMessage] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: initial?.name ?? '',
      color: initial?.color ?? CATEGORY_COLORS[0],
      icon: initial?.icon ?? 'tag',
    },
  });
  const { errors, isSubmitting } = form.formState;
  const [color, icon, name] = useWatch({ control: form.control, name: ['color', 'icon', 'name'] });

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit({ name: v.name, color: v.color.toUpperCase(), icon: v.icon });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['name', 'color', 'icon']));
    }
  });

  const run = async (action: () => Promise<unknown>) => {
    setMessage(null);
    try {
      await action();
    } catch (error) {
      setConfirmDelete(false);
      // 422 "category": kategori sudah dipakai transaksi → arahkan ke arsip.
      setMessage(applyServerErrors(error, form.setError, []));
    }
  };

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <div className="flex items-center gap-3">
        <CategoryIcon category={{ color, icon }} className="size-10" />
        <span className="text-sm text-muted-foreground">{name.trim() || 'Pratinjau'} · {kind === 'expense' ? 'Pengeluaran' : 'Pemasukan'}</span>
      </div>
      <Field label="Nama" htmlFor="category-name" error={errors.name?.message}>
        <Input id="category-name" autoFocus={!initial} maxLength={60} aria-invalid={!!errors.name} {...form.register('name')} />
      </Field>

      <fieldset className="grid gap-1.5">
        <Label asChild><legend>Warna</legend></Label>
        <div className="flex flex-wrap items-center gap-2">
          {CATEGORY_COLORS.map((c) => (
            <button
              key={c}
              type="button"
              aria-label={`Warna ${c}`}
              aria-pressed={color.toUpperCase() === c}
              onClick={() => form.setValue('color', c, { shouldDirty: true, shouldValidate: true })}
              className="flex size-7 items-center justify-center rounded-full ring-offset-2 ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
              style={{ backgroundColor: c }}
            >
              {color.toUpperCase() === c && <Check className="size-4 text-white" />}
            </button>
          ))}
          <input
            type="color"
            aria-label="Warna lain"
            value={/^#[0-9A-Fa-f]{6}$/.test(color) ? color : '#000000'}
            onChange={(e) => form.setValue('color', e.target.value.toUpperCase(), { shouldDirty: true, shouldValidate: true })}
            className="size-7 cursor-pointer rounded-full border bg-transparent p-0"
          />
        </div>
        {errors.color && <p role="alert" className="text-sm text-destructive">{errors.color.message}</p>}
      </fieldset>

      <fieldset className="grid gap-1.5">
        <Label asChild><legend>Ikon</legend></Label>
        <div className="grid grid-cols-8 gap-1">
          {Object.entries(CATEGORY_ICONS).map(([key, Icon]) => (
            <button
              key={key}
              type="button"
              aria-label={`Ikon ${key}`}
              aria-pressed={icon === key}
              onClick={() => form.setValue('icon', key, { shouldDirty: true })}
              className={cn(
                'flex aspect-square items-center justify-center rounded-md border border-transparent text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                icon === key && 'border-primary bg-primary/10 text-primary',
              )}
            >
              <Icon className="size-4" />
            </button>
          ))}
        </div>
      </fieldset>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="flex flex-wrap gap-1">
          {onArchive && initial && (
            <Button type="button" variant="ghost" onClick={() => run(onArchive)}>{initial.archived_at ? 'Aktifkan lagi' : 'Arsipkan'}</Button>
          )}
          {onDelete && (confirmDelete ? (
            <span className="flex items-center gap-2 text-sm">
              Hapus permanen?
              <Button type="button" size="sm" variant="destructive" onClick={() => run(onDelete)}>Ya, hapus</Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => setConfirmDelete(false)}>Batal</Button>
            </span>
          ) : <Button type="button" variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>Hapus</Button>)}
        </span>
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : initial ? 'Simpan' : 'Tambah kategori'}</Button>
      </div>
    </form>
  );
}
