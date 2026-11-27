import { amountSchema, newClientId } from '@personal-finance/shared';
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
  to_goal_id: z.number().int().positive('Pilih tujuan.'),
  amount: amountSchema.nullable().refine((v) => v !== null, 'Nominal wajib diisi.').transform((v) => v as number),
  note: z.string().max(255, 'Catatan maksimal 255 karakter.'),
});
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

export interface MovePayload {
  to_goal_id: number;
  amount: number;
  note: string | null;
  client_id: string;
}

/** Pindah alokasi ke tujuan lain di rekening yang sama (uangnya tidak bergerak). */
export function MoveForm({ from, targets, onSubmit }: {
  from: SavingsGoal;
  targets: SavingsGoal[];
  onSubmit: (payload: MovePayload) => Promise<unknown>;
}) {
  const [clientId] = useState(newClientId);
  const [message, setMessage] = useState<string | null>(null);
  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: { to_goal_id: targets[0]?.id ?? 0, amount: null, note: '' },
  });
  const { errors, isSubmitting } = form.formState;

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit({ to_goal_id: v.to_goal_id, amount: v.amount, note: v.note.trim() || null, client_id: clientId });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['to_goal_id', 'amount', 'note']));
    }
  });

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <p className="text-sm text-muted-foreground">Pindahkan alokasi dari <span className="font-medium text-foreground">{from.name}</span>.</p>
      <Field label="Ke tujuan" htmlFor="move-to" error={errors.to_goal_id?.message}>
        <NativeSelect id="move-to" {...form.register('to_goal_id', { valueAsNumber: true })}>
          {targets.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
        </NativeSelect>
      </Field>
      <Field label="Nominal dipindah" htmlFor="move-amount" error={errors.amount?.message}>
        <Controller control={form.control} name="amount" render={({ field }) => (
          <MoneyInput id="move-amount" autoFocus value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.amount} className="h-11 text-lg" />
        )} />
      </Field>
      <Field label="Catatan" htmlFor="move-note" error={errors.note?.message}>
        <TextInput id="move-note" placeholder="Opsional" maxLength={255} {...form.register('note')} />
      </Field>
      <div className="flex justify-end">
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Memindahkan…' : 'Pindahkan'}</Button>
      </div>
    </form>
  );
}
