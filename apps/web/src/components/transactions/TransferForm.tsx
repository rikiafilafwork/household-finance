import { amountSchema, localDateSchema, newClientId } from '@household-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import type { Transfer, Wallet } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';

const schema = z
  .object({
    from_wallet_id: z.number().int().positive('Pilih wallet asal.'),
    to_wallet_id: z.number().int().positive('Pilih wallet tujuan.'),
    amount: amountSchema.nullable().refine((v) => v !== null, 'Nominal wajib diisi.').transform((v) => v as number),
    occurred_on: localDateSchema,
    note: z.string().max(255, 'Catatan maksimal 255 karakter.'),
  })
  .refine((v) => v.from_wallet_id !== v.to_wallet_id, { path: ['to_wallet_id'], message: 'Wallet asal dan tujuan harus berbeda.' });
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

export interface TransferPayload {
  from_wallet_id: number;
  to_wallet_id: number;
  amount: number;
  occurred_on: string;
  note: string | null;
}

export function TransferForm({ initial, wallets, today, onSubmit, onDelete }: {
  initial?: Transfer;
  wallets: Wallet[];
  today: string;
  onSubmit: (payload: TransferPayload, clientId: string) => Promise<unknown>;
  onDelete?: () => Promise<unknown>;
}) {
  const [clientId] = useState(newClientId);
  const [message, setMessage] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const options = wallets.filter((w) => !w.archived_at || w.id === initial?.from_wallet_id || w.id === initial?.to_wallet_id);

  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? { from_wallet_id: initial.from_wallet_id, to_wallet_id: initial.to_wallet_id, amount: initial.amount, occurred_on: initial.occurred_on, note: initial.note ?? '' }
      : { from_wallet_id: options[0]?.id ?? 0, to_wallet_id: options[1]?.id ?? 0, amount: null, occurred_on: today, note: '' },
  });
  const { errors, isSubmitting } = form.formState;

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit({ from_wallet_id: v.from_wallet_id, to_wallet_id: v.to_wallet_id, amount: v.amount, occurred_on: v.occurred_on, note: v.note.trim() || null }, clientId);
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['from_wallet_id', 'to_wallet_id', 'amount', 'occurred_on', 'note']));
    }
  });

  const walletSelect = (name: 'from_wallet_id' | 'to_wallet_id', label: string) => (
    <Field label={label} htmlFor={name} error={errors[name]?.message}>
      <NativeSelect id={name} aria-invalid={!!errors[name]} {...form.register(name, { valueAsNumber: true })}>
        {options.map((w) => <option key={w.id} value={w.id}>{w.name}{w.visibility === 'private' ? ' (pribadi)' : ''}</option>)}
      </NativeSelect>
    </Field>
  );

  if (options.length < 2) {
    return <p className="text-sm text-muted-foreground">Transfer butuh minimal dua wallet aktif. Tambahkan wallet di halaman Wallet.</p>;
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <Field label="Nominal" htmlFor="amount" error={errors.amount?.message}>
        <Controller control={form.control} name="amount" render={({ field }) => (
          <MoneyInput id="amount" autoFocus={!initial} value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.amount} className="h-11 text-lg" />
        )} />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        {walletSelect('from_wallet_id', 'Dari')}
        {walletSelect('to_wallet_id', 'Ke')}
      </div>
      <Field label="Tanggal" htmlFor="occurred_on" error={errors.occurred_on?.message}>
        <Input id="occurred_on" type="date" {...form.register('occurred_on')} />
      </Field>
      <Field label="Catatan" htmlFor="note" error={errors.note?.message}>
        <Input id="note" placeholder="Opsional, mis. tarik tunai" maxLength={255} {...form.register('note')} />
      </Field>
      <p className="text-xs text-muted-foreground">Transfer tidak dihitung sebagai pemasukan/pengeluaran dan tidak memengaruhi budget.</p>
      <div className="flex flex-wrap items-center justify-between gap-2">
        {onDelete ? (
          confirmDelete ? (
            <span className="flex items-center gap-2 text-sm">
              Hapus transfer ini?
              <Button type="button" size="sm" variant="destructive" onClick={async () => { try { await onDelete(); } catch { setConfirmDelete(false); } }}>Ya, hapus</Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => setConfirmDelete(false)}>Batal</Button>
            </span>
          ) : <Button type="button" variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>Hapus</Button>
        ) : <span />}
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : 'Simpan transfer'}</Button>
      </div>
    </form>
  );
}
