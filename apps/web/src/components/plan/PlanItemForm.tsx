import { amountSchema } from '@personal-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import type { PlanItem, PlanItemBody, PlanItemType, PlanMode } from '@/api/plan';
import type { Account, Category } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { Button } from '@/components/ui/button';
import { Input as TextInput } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';
import { cn } from '@/lib/utils';

const schema = z.object({
  type: z.enum(['income', 'expense']),
  mode: z.enum(['checklist', 'envelope']),
  name: z.string().trim().min(1, 'Nama wajib diisi.').max(100, 'Nama maksimal 100 karakter.'),
  category_id: z.number().int().positive('Pilih kategori.'),
  planned_amount: amountSchema.nullable().refine((v) => v !== null, 'Nominal rencana wajib diisi.').transform((v) => v as number),
  account_id: z.number().int().nullable(),
  due_on: z.string(),
});
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

const MODES: { value: PlanMode; label: string; hint: string }[] = [
  { value: 'checklist', label: 'Checklist', hint: 'Sekali bayar/terima, dicentang saat terjadi (tagihan, cicilan, gaji).' },
  { value: 'envelope', label: 'Amplop', hint: 'Batas pengeluaran sebulan; terisi otomatis dari transaksi kategorinya.' },
];

export function Segmented<T extends string>({ value, options, onChange, disabled, label }: {
  value: T;
  options: { value: T; label: string }[];
  onChange: (v: T) => void;
  disabled?: boolean;
  label: string;
}) {
  return (
    <div role="radiogroup" aria-label={label} className="grid auto-cols-fr grid-flow-col gap-1 rounded-lg bg-muted p-1">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          role="radio"
          aria-checked={value === o.value}
          disabled={disabled}
          onClick={() => onChange(o.value)}
          className={cn(
            'rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground disabled:cursor-not-allowed disabled:opacity-60',
            value === o.value && 'bg-background text-foreground shadow-sm',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

/**
 * Tambah / ubah item rencana. Item yang sudah dicentang: jenis, mode, dan kategori terkunci
 * (server juga menolak) — batalkan centang dulu untuk mengubahnya.
 */
export function PlanItemForm({ month, initial, preset, accounts, categories, onSubmit }: {
  month: string;
  initial?: PlanItem;
  /** Bawaan untuk item baru (dari tombol "+ Tambah" per bagian). */
  preset?: { type: PlanItemType; mode: PlanMode };
  accounts: Account[];
  categories: Category[];
  onSubmit: (body: PlanItemBody) => Promise<unknown>;
}) {
  const [message, setMessage] = useState<string | null>(null);
  const locked = initial?.status === 'done';
  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? {
          type: initial.type, mode: initial.mode, name: initial.name, category_id: initial.category_id,
          planned_amount: initial.planned_amount, account_id: initial.account_id, due_on: initial.due_on ?? '',
        }
      : { type: preset?.type ?? 'expense', mode: preset?.mode ?? 'checklist', name: '', category_id: 0, planned_amount: null, account_id: null, due_on: '' },
  });
  const { errors, isSubmitting } = form.formState;
  const [type, mode] = useWatch({ control: form.control, name: ['type', 'mode'] });

  const categoryOptions = categories.filter((c) => c.kind === type && (!c.archived_at || c.id === initial?.category_id));
  const accountOptions = accounts.filter((a) => !a.archived_at || a.id === initial?.account_id);
  const [y, m] = month.split('-').map(Number) as [number, number];
  const lastDay = new Date(Date.UTC(y, m, 0)).getUTCDate();

  const setType = (t: PlanItemType) => {
    form.setValue('type', t);
    form.setValue('category_id', 0); // kategori pemasukan ≠ kategori pengeluaran
  };

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    const isChecklist = v.mode === 'checklist';
    try {
      await onSubmit({
        type: v.type,
        mode: v.mode,
        name: v.name,
        category_id: v.category_id,
        planned_amount: v.planned_amount,
        account_id: isChecklist ? v.account_id : null,
        due_on: isChecklist && v.due_on ? v.due_on : null,
      });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['type', 'mode', 'name', 'category_id', 'planned_amount', 'account_id', 'due_on']));
    }
  });

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      {locked && (
        <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">Item sudah dicentang: jenis, mode, dan kategori terkunci. Batalkan centang dulu untuk mengubahnya.</p>
      )}
      <Segmented label="Jenis" value={type} disabled={locked} onChange={setType} options={[{ value: 'expense', label: 'Pengeluaran' }, { value: 'income', label: 'Pemasukan' }]} />
      <div className="grid gap-1.5">
        <Segmented label="Mode" value={mode} disabled={locked} onChange={(v) => form.setValue('mode', v)} options={MODES} />
        <p className="text-xs text-muted-foreground">{MODES.find((o) => o.value === mode)?.hint}</p>
      </div>
      <Field label="Nama" htmlFor="item-name" error={errors.name?.message}>
        <TextInput id="item-name" autoFocus={!initial} maxLength={100} placeholder={mode === 'envelope' ? 'mis. Makan' : 'mis. Listrik'} aria-invalid={!!errors.name} {...form.register('name')} />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Kategori" htmlFor="item-category" error={errors.category_id?.message}>
          <NativeSelect id="item-category" disabled={locked} aria-invalid={!!errors.category_id} {...form.register('category_id', { valueAsNumber: true })}>
            <option value={0} disabled>Pilih…</option>
            {categoryOptions.map((c) => <option key={c.id} value={c.id}>{c.name}{c.archived_at ? ' (diarsip)' : ''}</option>)}
          </NativeSelect>
        </Field>
        <Field label="Rencana" htmlFor="item-amount" error={errors.planned_amount?.message}>
          <Controller control={form.control} name="planned_amount" render={({ field }) => (
            <MoneyInput id="item-amount" value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.planned_amount} />
          )} />
        </Field>
      </div>
      {mode === 'checklist' && (
        <div className="grid grid-cols-2 gap-3">
          <Field label="Akun bawaan" htmlFor="item-account" error={errors.account_id?.message}>
            <NativeSelect
              id="item-account"
              {...form.register('account_id', { setValueAs: (v: string) => (v === '' || v === null ? null : Number(v)) })}
            >
              <option value="">—</option>
              {accountOptions.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </NativeSelect>
          </Field>
          <Field label="Jatuh tempo" htmlFor="item-due" error={errors.due_on?.message}>
            <TextInput id="item-due" type="date" min={`${month}-01`} max={`${month}-${String(lastDay).padStart(2, '0')}`} {...form.register('due_on')} />
          </Field>
        </div>
      )}
      <div className="flex justify-end">
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : initial ? 'Simpan' : 'Tambah item'}</Button>
      </div>
    </form>
  );
}
