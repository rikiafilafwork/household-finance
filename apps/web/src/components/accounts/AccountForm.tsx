import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import type { Account, AccountType } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { MoneyText } from '@/components/money/MoneyText';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';
import { accountTypeLabel, ACCOUNT_TYPES } from './accountMeta';

const schema = z.object({
  name: z.string().trim().min(1, 'Nama akun wajib diisi.').max(60, 'Nama maksimal 60 karakter.'),
  type: z.enum(['bank', 'ewallet', 'cash']),
  opening_balance: z.number().int().nullable(),
});
type Values = z.infer<typeof schema>;

export interface AccountPayload {
  name: string;
  type: AccountType;
  opening_balance: number;
}

/** Tambah / ubah akun. Saat mengubah, arsip & hapus ada di bawah form. */
export function AccountForm({ initial, onSubmit, onArchive, onDelete }: {
  initial?: Account;
  onSubmit: (payload: AccountPayload) => Promise<unknown>;
  onArchive?: () => Promise<unknown>;
  onDelete?: () => Promise<unknown>;
}) {
  const [message, setMessage] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? { name: initial.name, type: initial.type, opening_balance: initial.opening_balance }
      : { name: '', type: 'bank', opening_balance: null },
  });
  const { errors, isSubmitting } = form.formState;
  const opening = useWatch({ control: form.control, name: 'opening_balance' }) ?? 0;

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit({ name: v.name, type: v.type, opening_balance: v.opening_balance ?? 0 });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['name', 'type', 'opening_balance']));
    }
  });

  const run = async (action: () => Promise<unknown>) => {
    setMessage(null);
    try {
      await action();
    } catch (error) {
      setConfirmDelete(false);
      setMessage(applyServerErrors(error, form.setError, []));
    }
  };

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <Field label="Nama" htmlFor="account-name" error={errors.name?.message}>
        <Input id="account-name" autoFocus={!initial} placeholder="mis. BCA, GoPay, Dompet" maxLength={60} aria-invalid={!!errors.name} {...form.register('name')} />
      </Field>
      <Field label="Jenis" htmlFor="account-type" error={errors.type?.message}>
        <NativeSelect id="account-type" {...form.register('type')}>
          {ACCOUNT_TYPES.map((t) => <option key={t} value={t}>{accountTypeLabel[t]}</option>)}
        </NativeSelect>
      </Field>
      <Field
        label="Saldo awal"
        htmlFor="account-opening"
        error={errors.opening_balance?.message}
        hint={initial
          ? <>Mengubah saldo awal menggeser saldo sekarang dengan selisih yang sama (jadi <MoneyText amount={initial.balance + (opening - initial.opening_balance)} />). Untuk mencocokkan dengan saldo asli, pakai “Sesuaikan saldo”.</>
          : 'Saldo saat akun mulai dicatat. Boleh 0.'}
      >
        <Controller control={form.control} name="opening_balance" render={({ field }) => (
          <MoneyInput id="account-opening" allowNegative value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.opening_balance} />
        )} />
      </Field>
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
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : initial ? 'Simpan' : 'Tambah akun'}</Button>
      </div>
    </form>
  );
}
