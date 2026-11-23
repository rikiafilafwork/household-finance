import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';
import { z } from 'zod';
import type { User } from '@/api/types';
import { useChangePassword, useMe, useUpdateProfile } from '@/auth/useAuth';
import { Field, FormMessage } from '@/components/form/Field';
import { NativeSelect } from '@/components/form/NativeSelect';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { todayIn } from '@/lib/dates';
import { applyServerErrors } from '@/lib/form-errors';

/** Zona Indonesia di atas; sisanya daftar IANA dari browser. */
const INDONESIA = [
  { value: 'Asia/Jakarta', label: 'WIB — Asia/Jakarta' },
  { value: 'Asia/Makassar', label: 'WITA — Asia/Makassar' },
  { value: 'Asia/Jayapura', label: 'WIT — Asia/Jayapura' },
];

function supportedTimezones(): string[] {
  try {
    return Intl.supportedValuesOf('timeZone');
  } catch {
    return [];
  }
}

/** "Hari ini" di zona terpilih, atau '' kalau nama zonanya tidak dikenal browser. */
function safeToday(timezone: string): string {
  try {
    return todayIn(timezone);
  } catch {
    return '';
  }
}

export function timezoneOptions(current: string): string[] {
  const rest = new Set(supportedTimezones());
  rest.add(current);
  INDONESIA.forEach((z) => rest.delete(z.value));
  return [...rest].sort();
}

const profileSchema = z.object({
  name: z.string().trim().min(1, 'Nama wajib diisi.').max(255),
  timezone: z.string().min(1, 'Zona waktu wajib dipilih.'),
});
type ProfileValues = z.infer<typeof profileSchema>;

function ProfileForm({ user }: { user: User }) {
  const update = useUpdateProfile();
  const [message, setMessage] = useState<string | null>(null);
  const form = useForm<ProfileValues>({ resolver: zodResolver(profileSchema), defaultValues: { name: user.name, timezone: user.timezone } });
  const { errors, isSubmitting, isDirty } = form.formState;
  const timezone = useWatch({ control: form.control, name: 'timezone' });

  const onSubmit = form.handleSubmit(async (values) => {
    setMessage(null);
    try {
      const saved = await update.mutateAsync(values);
      form.reset({ name: saved.name, timezone: saved.timezone });
      toast.success('Profil disimpan.');
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['name', 'timezone']));
    }
  });

  const today = safeToday(timezone);

  return (
    <form onSubmit={onSubmit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <Field label="Email" htmlFor="email" hint="Email tidak bisa diubah.">
        <Input id="email" value={user.email} disabled readOnly />
      </Field>
      <Field label="Nama" htmlFor="name" error={errors.name?.message}>
        <Input id="name" autoComplete="name" aria-invalid={!!errors.name} {...form.register('name')} />
      </Field>
      <Field
        label="Zona waktu"
        htmlFor="timezone"
        error={errors.timezone?.message}
        hint={<>Menentukan tanggal transaksi dan bulan rencana.{today && <> Hari ini di zona ini: <span className="font-medium text-foreground">{today}</span>.</>}</>}
      >
        <NativeSelect id="timezone" aria-invalid={!!errors.timezone} {...form.register('timezone')}>
          <optgroup label="Indonesia">
            {INDONESIA.map((z) => <option key={z.value} value={z.value}>{z.label}</option>)}
          </optgroup>
          <optgroup label="Lainnya">
            {timezoneOptions(user.timezone).map((z) => <option key={z} value={z}>{z}</option>)}
          </optgroup>
        </NativeSelect>
      </Field>
      <Button type="submit" disabled={isSubmitting || !isDirty} className="justify-self-start">
        {isSubmitting ? 'Menyimpan…' : 'Simpan profil'}
      </Button>
    </form>
  );
}

const passwordSchema = z
  .object({
    current_password: z.string().min(1, 'Kata sandi saat ini wajib diisi.'),
    password: z.string().min(8, 'Kata sandi minimal 8 karakter.'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Konfirmasi kata sandi tidak sama.' });
type PasswordValues = z.infer<typeof passwordSchema>;

function PasswordForm() {
  const change = useChangePassword();
  const [message, setMessage] = useState<string | null>(null);
  const form = useForm<PasswordValues>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  });
  const { errors, isSubmitting } = form.formState;

  const onSubmit = form.handleSubmit(async (values) => {
    setMessage(null);
    try {
      await change.mutateAsync(values);
      form.reset();
      toast.success('Kata sandi diganti. Perangkat mobile lain perlu masuk ulang.');
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['current_password', 'password', 'password_confirmation']));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate className="grid gap-4">
      <FormMessage message={message} />
      <Field label="Kata sandi saat ini" htmlFor="current_password" error={errors.current_password?.message}>
        <Input id="current_password" type="password" autoComplete="current-password" aria-invalid={!!errors.current_password} {...form.register('current_password')} />
      </Field>
      <Field label="Kata sandi baru" htmlFor="password" error={errors.password?.message} hint="Minimal 8 karakter.">
        <Input id="password" type="password" autoComplete="new-password" aria-invalid={!!errors.password} {...form.register('password')} />
      </Field>
      <Field label="Ulangi kata sandi baru" htmlFor="password_confirmation" error={errors.password_confirmation?.message}>
        <Input id="password_confirmation" type="password" autoComplete="new-password" aria-invalid={!!errors.password_confirmation} {...form.register('password_confirmation')} />
      </Field>
      <Button type="submit" variant="outline" disabled={isSubmitting} className="justify-self-start">
        {isSubmitting ? 'Memproses…' : 'Ganti kata sandi'}
      </Button>
    </form>
  );
}

/** Pengaturan → Profil: nama, zona waktu (Keputusan #12), ganti kata sandi. */
export function ProfilePage() {
  const { data: user } = useMe();

  return (
    <div className="grid max-w-xl gap-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Profil</CardTitle>
          <CardDescription>Nama dan zona waktu Anda.</CardDescription>
        </CardHeader>
        <CardContent>{user ? <ProfileForm key={user.id} user={user} /> : <Skeleton className="h-48" />}</CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Kata sandi</CardTitle>
          <CardDescription>Mengganti kata sandi mengeluarkan aplikasi mobile di perangkat lain.</CardDescription>
        </CardHeader>
        <CardContent><PasswordForm /></CardContent>
      </Card>
    </div>
  );
}
