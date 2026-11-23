import { localDateSchema } from '@personal-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import type { Account } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { MoneyInput } from '@/components/money/MoneyInput';
import { MoneyText } from '@/components/money/MoneyText';
import { Button } from '@/components/ui/button';
import { Input as TextInput } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';

const schema = z.object({
  actual_balance: z.number().int().nullable().refine((v) => v !== null, 'Isi saldo yang sebenarnya.').transform((v) => v as number),
  occurred_on: localDateSchema,
  note: z.string().max(255, 'Catatan maksimal 255 karakter.'),
});
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

export interface AdjustPayload {
  actual_balance: number;
  occurred_on: string;
  note: string | null;
}

/**
 * "Sesuaikan saldo" (Keputusan #6): user mengetik saldo asli, server mencatat transaksi
 * penyesuaian sebesar selisihnya. Saldo awal tidak disentuh; batalkan dengan menghapus transaksinya.
 */
export function AdjustBalanceForm({ account, today, onSubmit }: {
  account: Account;
  today: string;
  onSubmit: (payload: AdjustPayload) => Promise<unknown>;
}) {
  const [message, setMessage] = useState<string | null>(null);
  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: { actual_balance: null, occurred_on: today, note: '' },
  });
  const { errors, isSubmitting } = form.formState;
  const actual = useWatch({ control: form.control, name: 'actual_balance' });
  const diff = actual === null ? null : actual - account.balance;

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit({ actual_balance: v.actual_balance, occurred_on: v.occurred_on, note: v.note.trim() || null });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['actual_balance', 'occurred_on', 'note']));
    }
  });

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <p className="text-sm text-muted-foreground">
        Saldo tercatat <MoneyText amount={account.balance} tone="balance" className="font-medium text-foreground" />.
        Isi saldo yang sebenarnya (mis. dari aplikasi bank atau uang di dompet).
      </p>
      <Field label="Saldo sebenarnya" htmlFor="actual_balance" error={errors.actual_balance?.message}>
        <Controller control={form.control} name="actual_balance" render={({ field }) => (
          <MoneyInput id="actual_balance" autoFocus allowNegative value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.actual_balance} className="h-11 text-lg" />
        )} />
      </Field>
      {diff !== null && (
        <p data-testid="adjust-diff" className="rounded-md bg-muted px-3 py-2 text-sm">
          {diff === 0
            ? 'Saldo sudah sesuai — tidak ada yang dicatat.'
            : <>Akan dicatat penyesuaian <MoneyText amount={diff} tone="signed" className="font-medium" />.</>}
        </p>
      )}
      <div className="grid grid-cols-2 gap-3">
        <Field label="Tanggal" htmlFor="adjust-date" error={errors.occurred_on?.message}>
          <TextInput id="adjust-date" type="date" {...form.register('occurred_on')} />
        </Field>
        <Field label="Catatan" htmlFor="adjust-note" error={errors.note?.message}>
          <TextInput id="adjust-note" placeholder="Opsional" maxLength={255} {...form.register('note')} />
        </Field>
      </div>
      <div className="flex justify-end">
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : 'Sesuaikan'}</Button>
      </div>
    </form>
  );
}
