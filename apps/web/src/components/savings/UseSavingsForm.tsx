import { amountSchema, localDateSchema, newClientId } from '@personal-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import type { Category, SavingsGoal } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { Button } from '@/components/ui/button';
import { Input as TextInput } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';

const schema = z.object({
  amount: amountSchema.nullable().refine((v) => v !== null, 'Nominal wajib diisi.').transform((v) => v as number),
  category_id: z.number().int().positive('Pilih kategori.'),
  occurred_on: localDateSchema,
  note: z.string().max(255, 'Catatan maksimal 255 karakter.'),
});
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

export interface UsePayload {
  amount: number;
  category_id: number;
  occurred_on: string;
  note: string | null;
  client_id: string;
}

/**
 * "Gunakan": uang tabungan dipakai untuk belanja → server mencatat pengeluaran dari rekening
 * tabungan dan mengurangi alokasi tujuan sebesar itu. `client_id` sekali per form.
 */
export function UseSavingsForm({ goal, categories, today, onSubmit }: {
  goal: SavingsGoal;
  categories: Category[];
  today: string;
  onSubmit: (payload: UsePayload) => Promise<unknown>;
}) {
  const [clientId] = useState(newClientId);
  const [message, setMessage] = useState<string | null>(null);
  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: { amount: null, category_id: categories[0]?.id ?? 0, occurred_on: today, note: '' },
  });
  const { errors, isSubmitting } = form.formState;

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit({ amount: v.amount, category_id: v.category_id, occurred_on: v.occurred_on, note: v.note.trim() || null, client_id: clientId });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['amount', 'category_id', 'occurred_on', 'note']));
    }
  });

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <p className="text-sm text-muted-foreground">Pakai uang dari <span className="font-medium text-foreground">{goal.name}</span> untuk pengeluaran.</p>
      <Field label="Nominal dipakai" htmlFor="use-amount" error={errors.amount?.message}>
        <Controller control={form.control} name="amount" render={({ field }) => (
          <MoneyInput id="use-amount" autoFocus value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.amount} className="h-11 text-lg" />
        )} />
      </Field>
      <Field label="Kategori" htmlFor="use-category" error={errors.category_id?.message}>
        <NativeSelect id="use-category" {...form.register('category_id', { valueAsNumber: true })}>
          {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </NativeSelect>
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Tanggal" htmlFor="use-date" error={errors.occurred_on?.message}>
          <TextInput id="use-date" type="date" {...form.register('occurred_on')} />
        </Field>
        <Field label="Catatan" htmlFor="use-note" error={errors.note?.message}>
          <TextInput id="use-note" placeholder="Opsional" maxLength={255} {...form.register('note')} />
        </Field>
      </div>
      <div className="flex justify-end">
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : 'Gunakan'}</Button>
      </div>
    </form>
  );
}
