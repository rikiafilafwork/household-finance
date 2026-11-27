import { amountSchema, localDateSchema, newClientId } from '@personal-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import type { CheckBody, PlanItem } from '@/api/plan';
import type { Account } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { MoneyText } from '@/components/money/MoneyText';
import { Button } from '@/components/ui/button';
import { Input as TextInput } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';
import { isBadDiff } from './planMeta';

const schema = z.object({
  amount: amountSchema.nullable().refine((v) => v !== null, 'Nominal wajib diisi.').transform((v) => v as number),
  account_id: z.number().int().positive('Pilih akun.'),
  occurred_on: localDateSchema,
  note: z.string().max(255, 'Catatan maksimal 255 karakter.'),
});
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

/**
 * Centang = catat transaksi (Keputusan #8). `client_id` dibuat sekali per form: kirim ulang setelah
 * jaringan putus memakai id yang sama → server menjawab 200 dengan transaksi yang sama, bukan dobel.
 */
export function CheckForm({ item, accounts, today, onSubmit }: {
  /** Item rencana, atau baris "perlu dicentang" Beranda (cukup bidang ini). */
  item: Pick<PlanItem, 'type' | 'name' | 'planned_amount' | 'account_id'>;
  accounts: Account[];
  today: string;
  onSubmit: (body: CheckBody) => Promise<unknown>;
}) {
  const [clientId] = useState(newClientId);
  const [message, setMessage] = useState<string | null>(null);
  const options = accounts.filter((a) => !a.archived_at);
  const preferred = options.find((a) => a.id === item.account_id) ?? options[0];

  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: { amount: item.planned_amount, account_id: preferred?.id ?? 0, occurred_on: today, note: item.name },
  });
  const { errors, isSubmitting } = form.formState;
  const amount = useWatch({ control: form.control, name: 'amount' });
  const diff = amount === null ? 0 : amount - item.planned_amount;

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit({ client_id: clientId, amount: v.amount, account_id: v.account_id, occurred_on: v.occurred_on, note: v.note.trim() || null });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['amount', 'account_id', 'occurred_on', 'note']));
    }
  });

  if (options.length === 0) {
    return <p className="text-sm text-muted-foreground">Belum ada akun aktif. Tambahkan akun di halaman Rekening dulu.</p>;
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <Field
        label={item.type === 'income' ? 'Nominal diterima' : 'Nominal dibayar'}
        htmlFor="check-amount"
        error={errors.amount?.message}
        hint={diff !== 0 && amount !== null
          ? <>Rencana <MoneyText amount={item.planned_amount} />, selisih <span className={isBadDiff(item, diff) ? 'text-destructive' : 'text-success'}>{diff > 0 ? '+' : '−'}<MoneyText amount={Math.abs(diff)} /></span></>
          : <>Sesuai rencana <MoneyText amount={item.planned_amount} /></>}
      >
        <Controller control={form.control} name="amount" render={({ field }) => (
          <MoneyInput id="check-amount" autoFocus value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.amount} className="h-11 text-lg" />
        )} />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label={item.type === 'income' ? 'Masuk ke' : 'Dari akun'} htmlFor="check-account" error={errors.account_id?.message}>
          <NativeSelect id="check-account" {...form.register('account_id', { valueAsNumber: true })}>
            {options.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </NativeSelect>
        </Field>
        <Field label="Tanggal" htmlFor="check-date" error={errors.occurred_on?.message}>
          <TextInput id="check-date" type="date" {...form.register('occurred_on')} />
        </Field>
      </div>
      <Field label="Catatan" htmlFor="check-note" error={errors.note?.message}>
        <TextInput id="check-note" maxLength={255} {...form.register('note')} />
      </Field>
      <div className="flex justify-end">
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : 'Centang & catat'}</Button>
      </div>
    </form>
  );
}
