import { amountSchema, localDateSchema, newClientId } from '@personal-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import type { Account, Category, Transaction } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';
import { cn } from '@/lib/utils';

const schema = z.object({
  type: z.enum(['expense', 'income']),
  amount: amountSchema.nullable().refine((v) => v !== null, 'Nominal wajib diisi.').transform((v) => v as number),
  account_id: z.number({ error: 'Pilih akun.' }).int().positive('Pilih akun.'),
  category_id: z.number().int().positive().nullable(),
  occurred_on: localDateSchema,
  note: z.string().max(255, 'Catatan maksimal 255 karakter.'),
});
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

export interface TransactionPayload {
  type: 'expense' | 'income';
  amount: number;
  account_id: number;
  category_id: number | null;
  occurred_on: string;
  note: string | null;
}

/**
 * Form pemasukan/pengeluaran. `client_id` dibuat SEKALI saat form dibuka dan dipakai ulang
 * kalau submit gagal lalu diulang (mis. koneksi putus setelah server menyimpan): server
 * menjawab 200 dengan transaksi yang sama, tidak dobel (Keputusan #15). Form berikutnya = id baru.
 */
export function TransactionForm({
  initial,
  defaultType = 'expense',
  accounts,
  categories,
  today,
  onSubmit,
  onDelete,
  submitLabel,
}: {
  initial?: Transaction;
  defaultType?: 'expense' | 'income';
  accounts: Account[];
  categories: Category[];
  today: string;
  onSubmit: (payload: TransactionPayload, clientId: string) => Promise<unknown>;
  onDelete?: () => Promise<unknown>;
  submitLabel?: string;
}) {
  const [clientId] = useState(newClientId);
  const [message, setMessage] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const isEdit = !!initial;

  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? {
          type: initial.type === 'income' ? 'income' : 'expense',
          amount: Math.abs(initial.amount),
          account_id: initial.account_id,
          category_id: initial.category_id,
          occurred_on: initial.occurred_on,
          note: initial.note ?? '',
        }
      : { type: defaultType, amount: null, account_id: accounts.find((a) => !a.archived_at)?.id ?? 0, category_id: null, occurred_on: today, note: '' },
  });
  const { errors, isSubmitting } = form.formState;
  const type = useWatch({ control: form.control, name: 'type' });

  // Kategori aktif sesuai jenis; kategori lama yang sudah diarsip tetap boleh dipertahankan saat edit.
  const categoryOptions = categories.filter((c) => c.kind === type && (!c.archived_at || c.id === initial?.category_id));
  const accountOptions = accounts.filter((a) => !a.archived_at || a.id === initial?.account_id);

  const submit = form.handleSubmit(async (values) => {
    setMessage(null);
    try {
      await onSubmit(
        { type: values.type, amount: values.amount, account_id: values.account_id, category_id: values.category_id, occurred_on: values.occurred_on, note: values.note.trim() || null },
        clientId,
      );
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['type', 'amount', 'account_id', 'category_id', 'occurred_on', 'note']));
    }
  });

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />

      <div className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1" role="radiogroup" aria-label="Jenis transaksi">
        {(['expense', 'income'] as const).map((t) => (
          <button
            key={t}
            type="button"
            role="radio"
            aria-checked={type === t}
            onClick={() => {
              form.setValue('type', t);
              form.setValue('category_id', null);
            }}
            className={cn('rounded-md py-1.5 text-sm font-medium text-muted-foreground transition', type === t && 'bg-background text-foreground shadow-sm')}
          >
            {t === 'expense' ? 'Pengeluaran' : 'Pemasukan'}
          </button>
        ))}
      </div>

      <Field label="Nominal" htmlFor="amount" error={errors.amount?.message}>
        <Controller
          control={form.control}
          name="amount"
          render={({ field }) => (
            <MoneyInput id="amount" autoFocus={!isEdit} value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.amount} className="h-11 text-lg" />
          )}
        />
      </Field>

      <div className="grid grid-cols-2 gap-3">
        <Field label="Akun" htmlFor="account_id" error={errors.account_id?.message}>
          <NativeSelect id="account_id" aria-invalid={!!errors.account_id} {...form.register('account_id', { valueAsNumber: true })}>
            {accountOptions.length === 0 && <option value={0}>Belum ada akun</option>}
            {accountOptions.map((w) => (
              <option key={w.id} value={w.id}>{w.name}</option>
            ))}
          </NativeSelect>
        </Field>
        <Field label="Tanggal" htmlFor="occurred_on" error={errors.occurred_on?.message}>
          <Input id="occurred_on" type="date" aria-invalid={!!errors.occurred_on} {...form.register('occurred_on')} />
        </Field>
      </div>

      <Field label="Kategori" htmlFor="category_id" error={errors.category_id?.message}>
        <Controller
          control={form.control}
          name="category_id"
          render={({ field }) => (
            <NativeSelect id="category_id" value={field.value ?? ''} onChange={(e) => field.onChange(e.target.value === '' ? null : Number(e.target.value))} aria-invalid={!!errors.category_id}>
              <option value="">Tanpa kategori</option>
              {categoryOptions.map((c) => (
                <option key={c.id} value={c.id}>{c.name}{c.archived_at ? ' (diarsip)' : ''}</option>
              ))}
            </NativeSelect>
          )}
        />
      </Field>

      <Field label="Catatan" htmlFor="note" error={errors.note?.message}>
        <Input id="note" placeholder="Opsional, mis. belanja mingguan" maxLength={255} {...form.register('note')} />
      </Field>

      <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
        {onDelete ? (
          confirmDelete ? (
            <span className="flex items-center gap-2 text-sm">
              Hapus transaksi ini?
              <Button type="button" size="sm" variant="destructive" onClick={async () => { try { await onDelete(); } catch { setConfirmDelete(false); } }}>Ya, hapus</Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => setConfirmDelete(false)}>Batal</Button>
            </span>
          ) : (
            <Button type="button" variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>Hapus</Button>
          )
        ) : <span />}
        <Button type="submit" disabled={isSubmitting || accountOptions.length === 0}>
          {isSubmitting ? 'Menyimpan…' : (submitLabel ?? 'Simpan')}
        </Button>
      </div>
    </form>
  );
}
