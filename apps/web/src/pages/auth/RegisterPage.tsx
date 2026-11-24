import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { z } from 'zod';
import { useRegister } from '@/auth/useAuth';
import { Field, FormMessage } from '@/components/form/Field';
import { PasswordInput } from '@/components/form/PasswordInput';
import { AuthLayout } from '@/components/layout/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';
import { safeNext, withNext } from '@/lib/redirect';

const schema = z
  .object({
    name: z.string().trim().min(1, 'Nama wajib diisi.').max(255),
    email: z.string().trim().min(1, 'Email wajib diisi.').email('Format email tidak valid.'),
    password: z.string().min(8, 'Kata sandi minimal 8 karakter.'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Konfirmasi kata sandi tidak sama.' });
type Values = z.infer<typeof schema>;

function browserTimezone(): string | undefined {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || undefined;
  } catch {
    return undefined;
  }
}

export function RegisterPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const register = useRegister();
  const [message, setMessage] = useState<string | null>(null);
  const next = safeNext(params.get('next'));

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', email: params.get('email') ?? '', password: '', password_confirmation: '' },
  });
  const { errors, isSubmitting } = form.formState;

  const onSubmit = form.handleSubmit(async (values) => {
    setMessage(null);
    try {
      // Zona waktu perangkat sebagai awal; bisa diubah di Pengaturan → Profil (Keputusan #12).
      await register.mutateAsync({ ...values, timezone: browserTimezone() });
      navigate(next, { replace: true });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['name', 'email', 'password', 'password_confirmation']));
    }
  });

  return (
    <AuthLayout
      title="Daftar"
      description="Buat akun untuk mencatat keuangan pribadi Anda."
      footer={<>Sudah punya akun? <Link className="font-medium text-primary underline-offset-4 hover:underline" to={params.get('next') ? withNext('/login', next) : '/login'}>Masuk</Link></>}
    >
      <form onSubmit={onSubmit} noValidate className="grid gap-4">
        <FormMessage message={message} />
        <Field label="Nama" htmlFor="name" error={errors.name?.message}>
          <Input id="name" autoComplete="name" autoFocus aria-invalid={!!errors.name} {...form.register('name')} />
        </Field>
        <Field label="Email" htmlFor="email" error={errors.email?.message}>
          <Input id="email" type="email" autoComplete="email" aria-invalid={!!errors.email} {...form.register('email')} />
        </Field>
        <Field label="Kata sandi" htmlFor="password" error={errors.password?.message} hint="Minimal 8 karakter.">
          <PasswordInput id="password" autoComplete="new-password" aria-invalid={!!errors.password} {...form.register('password')} />
        </Field>
        <Field label="Ulangi kata sandi" htmlFor="password_confirmation" error={errors.password_confirmation?.message}>
          <PasswordInput id="password_confirmation" autoComplete="new-password" aria-invalid={!!errors.password_confirmation} {...form.register('password_confirmation')} />
        </Field>
        <Button type="submit" disabled={isSubmitting} className="w-full">
          {isSubmitting ? 'Memproses…' : 'Daftar'}
        </Button>
      </form>
    </AuthLayout>
  );
}
