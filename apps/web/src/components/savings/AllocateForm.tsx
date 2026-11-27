import { amountSchema, localDateSchema, newClientId } from '@personal-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import type { SavingsGoal } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { Button } from '@/components/ui/button';
import { Input as TextInput } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';

const schema = z.object({
  goal_id: z.number().int().positive('Pilih tujuan.'),
  amount: amountSchema.nullable().refine((v) => v !== null, 'Nominal wajib diisi.').transform((v) => v as number),
  occurred_on: localDateSchema,
  note: z.string().max(255, 'Catatan maksimal 255 karakter.'),
});
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

export interface AllocatePayload {
  amount: number; // bertanda
  occurred_on: string;
  note: string | null;
  client_id: string;
}

/**
 * Tambah (+) atau kurangi (−) alokasi. Pengguna selalu mengetik angka positif; tanda dikirim
 * sesuai `mode`. `pickGoal` menampilkan pilihan tujuan (aksi "Kurangi alokasi" di banner).
 * `client_id` dibuat sekali per form: kirim ulang memakai id yang sama, bukan dobel.
 */
export function AllocateForm({ goals, goal, mode, pickGoal = false, today, onSubmit }: {
  goals: SavingsGoal[];
  goal?: SavingsGoal;
  mode: 'add' | 'reduce';
  pickGoal?: boolean;
  today: string;
  onSubmit: (goalId: number, payload: AllocatePayload) => Promise<unknown>;
}) {
  const [clientId] = useState(newClientId);
  const [message, setMessage] = useState<string | null>(null);
  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: { goal_id: goal?.id ?? goals[0]?.id ?? 0, amount: null, occurred_on: today, note: '' },
  });
  const { errors, isSubmitting } = form.formState;

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit(v.goal_id, {
        amount: mode === 'reduce' ? -v.amount : v.amount,
        occurred_on: v.occurred_on,
        note: v.note.trim() || null,
        client_id: clientId,
      });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['amount', 'occurred_on', 'note']));
    }
  });

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      {pickGoal && (
        <Field label="Tujuan" htmlFor="alloc-goal" error={errors.goal_id?.message}>
          <NativeSelect id="alloc-goal" {...form.register('goal_id', { valueAsNumber: true })}>
            {goals.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </NativeSelect>
        </Field>
      )}
      <Field label={mode === 'add' ? 'Nominal ditambahkan' : 'Nominal dikurangi'} htmlFor="alloc-amount" error={errors.amount?.message}>
        <Controller control={form.control} name="amount" render={({ field }) => (
          <MoneyInput id="alloc-amount" autoFocus value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.amount} className="h-11 text-lg" />
        )} />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Tanggal" htmlFor="alloc-date" error={errors.occurred_on?.message}>
          <TextInput id="alloc-date" type="date" {...form.register('occurred_on')} />
        </Field>
        <Field label="Catatan" htmlFor="alloc-note" error={errors.note?.message}>
          <TextInput id="alloc-note" placeholder="Opsional" maxLength={255} {...form.register('note')} />
        </Field>
      </div>
      <div className="flex justify-end">
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : mode === 'add' ? 'Tambah alokasi' : 'Kurangi alokasi'}</Button>
      </div>
    </form>
  );
}
