import { localDateSchema } from '@personal-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';
import type { GoalBody } from '@/api/savings';
import type { Account, SavingsGoal } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { Button } from '@/components/ui/button';
import { Input as TextInput } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';

const schema = z.object({
  account_id: z.number().int().positive('Pilih rekening tabungan.'),
  name: z.string().trim().min(1, 'Nama wajib diisi.').max(100, 'Nama maksimal 100 karakter.'),
  target_amount: z.number().int().min(1, 'Target harus lebih dari 0.').nullable().refine((v) => v !== null, 'Target wajib diisi.').transform((v) => v as number),
  target_date: z.union([z.literal(''), localDateSchema]),
});
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

export type GoalPayload = GoalBody & { account_id: number };

/** Tujuan baru atau ubah tujuan. Rekening tidak bisa dipindah setelah dibuat. */
export function GoalForm({ initial, accounts, onSubmit, onArchive, onAchieve, onDelete }: {
  initial?: SavingsGoal;
  accounts: Account[];
  onSubmit: (payload: GoalPayload) => Promise<unknown>;
  onArchive?: () => Promise<unknown>;
  onAchieve?: () => Promise<unknown>;
  onDelete?: () => Promise<unknown>;
}) {
  const [message, setMessage] = useState<string | null>(null);
  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: {
      account_id: initial?.account_id ?? accounts[0]?.id ?? 0,
      name: initial?.name ?? '',
      target_amount: initial?.target_amount ?? null,
      target_date: initial?.target_date ?? '',
    },
  });
  const { errors, isSubmitting } = form.formState;

  const run = async (action: () => Promise<unknown>) => {
    setMessage(null);
    try {
      await action();
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['account_id', 'name', 'target_amount', 'target_date']));
    }
  };
  const submit = form.handleSubmit((v) => run(() => onSubmit({
    account_id: v.account_id, name: v.name, target_amount: v.target_amount, target_date: v.target_date || null,
  })));

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <Field label="Nama tujuan" htmlFor="goal-name" error={errors.name?.message}>
        <TextInput id="goal-name" autoFocus placeholder="mis. Dana darurat" maxLength={100} {...form.register('name')} />
      </Field>
      <Field label="Target" htmlFor="goal-target" error={errors.target_amount?.message}>
        <Controller control={form.control} name="target_amount" render={({ field }) => (
          <MoneyInput id="goal-target" value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.target_amount} />
        )} />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Tenggat" htmlFor="goal-date" error={errors.target_date?.message} hint="Opsional">
          <TextInput id="goal-date" type="date" {...form.register('target_date')} />
        </Field>
        <Field label="Rekening tabungan" htmlFor="goal-account" error={errors.account_id?.message}>
          <NativeSelect id="goal-account" disabled={!!initial} {...form.register('account_id', { valueAsNumber: true })}>
            {accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </NativeSelect>
        </Field>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap gap-2">
          {onAchieve && <Button type="button" variant="outline" onClick={() => void run(onAchieve)}>{initial?.achieved_at ? 'Batal tercapai' : 'Tandai tercapai'}</Button>}
          {onArchive && <Button type="button" variant="outline" onClick={() => void run(onArchive)}>Arsipkan</Button>}
          {onDelete && <Button type="button" variant="ghost" className="text-destructive" onClick={() => void run(onDelete)}>Hapus</Button>}
        </div>
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : 'Simpan'}</Button>
      </div>
    </form>
  );
}
