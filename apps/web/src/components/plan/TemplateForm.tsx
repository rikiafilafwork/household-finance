import { amountSchema } from '@personal-finance/shared';
import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';
import type { PlanItemType, PlanMode } from '@/api/plan';
import type { PlanTemplate, TemplateBody, TemplateFrequency } from '@/api/templates';
import type { Account, Category } from '@/api/types';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { MoneyInput } from '@/components/money/MoneyInput';
import { Button } from '@/components/ui/button';
import { Input as TextInput } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';
import { Segmented } from './PlanItemForm';
import { MONTH_NAMES } from './templateMeta';

const optionalNumber = (v: unknown) => (v === '' || v === null || v === undefined || Number.isNaN(v) ? null : Number(v));

const schema = z
  .object({
    type: z.enum(['income', 'expense']),
    mode: z.enum(['checklist', 'envelope']),
    name: z.string().trim().min(1, 'Nama wajib diisi.').max(100, 'Nama maksimal 100 karakter.'),
    category_id: z.number().int().positive('Pilih kategori.'),
    planned_amount: amountSchema.nullable().refine((v) => v !== null, 'Nominal rencana wajib diisi.').transform((v) => v as number),
    account_id: z.number().int().nullable(),
    frequency: z.enum(['monthly', 'yearly']),
    day_of_month: z.number().int().min(1, 'Tanggal 1–31.').max(31, 'Tanggal 1–31.').nullable(),
    month_of_year: z.number().int().min(1).max(12).nullable(),
    auto_post: z.boolean(),
    is_active: z.boolean(),
  })
  // Aturan yang sama dengan server (SavePlanTemplate) supaya kesalahan terlihat sebelum dikirim.
  .superRefine((v, ctx) => {
    if (v.frequency === 'yearly' && v.month_of_year === null) {
      ctx.addIssue({ code: 'custom', path: ['month_of_year'], message: 'Pilih bulannya.' });
    }
    if (v.auto_post && v.mode === 'checklist') {
      if (v.day_of_month === null) ctx.addIssue({ code: 'custom', path: ['day_of_month'], message: 'Auto-post butuh tanggal.' });
      if (v.account_id === null) ctx.addIssue({ code: 'custom', path: ['account_id'], message: 'Auto-post butuh akun.' });
    }
  });
type Input = z.input<typeof schema>;
type Output = z.output<typeof schema>;

const FIELDS = ['type', 'mode', 'name', 'category_id', 'planned_amount', 'account_id', 'frequency', 'day_of_month', 'month_of_year', 'auto_post', 'is_active'];

/** Tambah / ubah template. Mengubah template hanya berlaku untuk bulan yang dibuat setelahnya. */
export function TemplateForm({ initial, preset, accounts, categories, onSubmit }: {
  initial?: PlanTemplate;
  preset?: { type: PlanItemType; mode: PlanMode };
  accounts: Account[];
  categories: Category[];
  onSubmit: (body: TemplateBody) => Promise<unknown>;
}) {
  const [message, setMessage] = useState<string | null>(null);
  const form = useForm<Input, unknown, Output>({
    resolver: zodResolver(schema),
    defaultValues: initial
      ? {
          type: initial.type, mode: initial.mode, name: initial.name, category_id: initial.category_id, planned_amount: initial.planned_amount,
          account_id: initial.account_id, frequency: initial.frequency, day_of_month: initial.day_of_month, month_of_year: initial.month_of_year,
          auto_post: initial.auto_post, is_active: initial.is_active,
        }
      : {
          type: preset?.type ?? 'expense', mode: preset?.mode ?? 'checklist', name: '', category_id: 0, planned_amount: null,
          account_id: null, frequency: 'monthly', day_of_month: null, month_of_year: null, auto_post: false, is_active: true,
        },
  });
  const { errors, isSubmitting } = form.formState;
  const [type, mode, frequency] = useWatch({ control: form.control, name: ['type', 'mode', 'frequency'] });
  const checklist = mode === 'checklist';

  const categoryOptions = categories.filter((c) => c.kind === type && (!c.archived_at || c.id === initial?.category_id));
  const accountOptions = accounts.filter((a) => !a.archived_at || a.id === initial?.account_id);

  const setType = (t: PlanItemType) => {
    form.setValue('type', t);
    form.setValue('category_id', 0);
  };
  const setFrequency = (f: TemplateFrequency) => {
    form.setValue('frequency', f);
    if (f === 'monthly') form.setValue('month_of_year', null);
  };

  const submit = form.handleSubmit(async (v) => {
    setMessage(null);
    try {
      await onSubmit({
        ...v,
        // Amplop: tanpa tanggal, akun, dan auto-post (server juga menolak).
        account_id: checklist ? v.account_id : null,
        day_of_month: checklist ? v.day_of_month : null,
        auto_post: checklist ? v.auto_post : false,
        month_of_year: v.frequency === 'yearly' ? v.month_of_year : null,
      });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, FIELDS));
    }
  });

  return (
    <form onSubmit={submit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <Segmented label="Jenis" value={type} onChange={setType} options={[{ value: 'expense', label: 'Pengeluaran' }, { value: 'income', label: 'Pemasukan' }]} />
      <Segmented label="Mode" value={mode} onChange={(v) => form.setValue('mode', v)} options={[{ value: 'checklist', label: 'Checklist' }, { value: 'envelope', label: 'Amplop' }]} />
      <Field label="Nama" htmlFor="tpl-name" error={errors.name?.message}>
        <TextInput id="tpl-name" autoFocus={!initial} maxLength={100} placeholder={checklist ? 'mis. Gaji, Listrik, Cicilan motor' : 'mis. Makan'} aria-invalid={!!errors.name} {...form.register('name')} />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Kategori" htmlFor="tpl-category" error={errors.category_id?.message}>
          <NativeSelect id="tpl-category" aria-invalid={!!errors.category_id} {...form.register('category_id', { valueAsNumber: true })}>
            <option value={0} disabled>Pilih…</option>
            {categoryOptions.map((c) => <option key={c.id} value={c.id}>{c.name}{c.archived_at ? ' (diarsip)' : ''}</option>)}
          </NativeSelect>
        </Field>
        <Field label="Rencana" htmlFor="tpl-amount" error={errors.planned_amount?.message}>
          <Controller control={form.control} name="planned_amount" render={({ field }) => (
            <MoneyInput id="tpl-amount" value={field.value} onChange={field.onChange} onBlur={field.onBlur} invalid={!!errors.planned_amount} />
          )} />
        </Field>
      </div>

      <div className="grid gap-1.5">
        <Segmented label="Frekuensi" value={frequency} onChange={setFrequency} options={[{ value: 'monthly', label: 'Tiap bulan' }, { value: 'yearly', label: 'Tiap tahun' }]} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        {frequency === 'yearly' && (
          <Field label="Bulan" htmlFor="tpl-month" error={errors.month_of_year?.message}>
            <NativeSelect id="tpl-month" aria-invalid={!!errors.month_of_year} {...form.register('month_of_year', { setValueAs: optionalNumber })}>
              <option value="">Pilih…</option>
              {MONTH_NAMES.map((name, i) => <option key={name} value={i + 1}>{name}</option>)}
            </NativeSelect>
          </Field>
        )}
        {checklist && (
          <Field label="Tanggal jatuh tempo" htmlFor="tpl-day" error={errors.day_of_month?.message} hint="29–31 → hari terakhir di bulan pendek.">
            <TextInput id="tpl-day" type="number" inputMode="numeric" min={1} max={31} placeholder="1–31" aria-invalid={!!errors.day_of_month} {...form.register('day_of_month', { setValueAs: optionalNumber })} />
          </Field>
        )}
      </div>

      {checklist && (
        <>
          <Field label="Rekening bawaan" htmlFor="tpl-account" error={errors.account_id?.message}>
            <NativeSelect id="tpl-account" aria-invalid={!!errors.account_id} {...form.register('account_id', { setValueAs: optionalNumber })}>
              <option value="">—</option>
              {accountOptions.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </NativeSelect>
          </Field>
          <label className="flex items-start gap-3 rounded-md border p-3 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 accent-primary" {...form.register('auto_post')} />
            <span className="grid gap-0.5">
              <span className="font-medium">Centang otomatis saat jatuh tempo</span>
              <span className="text-xs text-muted-foreground">Untuk autodebet atau gaji yang pasti masuk. Transaksi dicatat di tanggal jatuh tempo dari akun bawaan; bisa dibatalkan seperti centang biasa.</span>
              {errors.auto_post?.message && <span role="alert" className="text-xs text-destructive">{errors.auto_post.message}</span>}
            </span>
          </label>
        </>
      )}

      {initial && (
        <label className="flex items-center gap-3 text-sm">
          <input type="checkbox" className="size-4 accent-primary" {...form.register('is_active')} />
          Aktif (dipakai saat membuat rencana bulan baru)
        </label>
      )}

      <div className="flex justify-end">
        <Button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Menyimpan…' : initial ? 'Simpan' : 'Tambah template'}</Button>
      </div>
    </form>
  );
}
