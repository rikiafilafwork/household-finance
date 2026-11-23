import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { z } from 'zod';
import { useLogin } from '@/auth/useAuth';
import { Field, FormMessage } from '@/components/form/Field';
import { AuthLayout } from '@/components/layout/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';
import { safeNext, withNext } from '@/lib/redirect';

const schema = z.object({
  email: z.string().trim().min(1, 'Email wajib diisi.').email('Format email tidak valid.'),
  password: z.string().min(1, 'Kata sandi wajib diisi.'),
  remember: z.boolean(),
});
type Values = z.infer<typeof schema>;

export function LoginPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const login = useLogin();
  const [message, setMessage] = useState<string | null>(null);
  const next = safeNext(params.get('next'));

  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { email: params.get('email') ?? '', password: '', remember: true },
  });
  const { errors, isSubmitting } = form.formState;

  const onSubmit = form.handleSubmit(async (values) => {
    setMessage(null);
    try {
      await login.mutateAsync(values);
      navigate(next, { replace: true });
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['email', 'password']));
      form.resetField('password');
    }
  });

  return (
    <AuthLayout
      title="Masuk"
      description="Rencanakan dan catat keuangan pribadi Anda."
      footer={<>Belum punya akun? <Link className="font-medium text-primary underline-offset-4 hover:underline" to={params.get('next') ? withNext('/register', next) : '/register'}>Daftar</Link></>}
    >
      <form onSubmit={onSubmit} noValidate className="grid gap-4">
        <FormMessage message={message} />
        <Field label="Email" htmlFor="email" error={errors.email?.message}>
          <Input id="email" type="email" autoComplete="email" autoFocus aria-invalid={!!errors.email} {...form.register('email')} />
        </Field>
        <Field label="Kata sandi" htmlFor="password" error={errors.password?.message}>
          <Input id="password" type="password" autoComplete="current-password" aria-invalid={!!errors.password} {...form.register('password')} />
        </Field>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4 accent-primary" {...form.register('remember')} />
          Ingat saya di perangkat ini
        </label>
        <Button type="submit" disabled={isSubmitting} className="w-full">
          {isSubmitting ? 'Memproses…' : 'Masuk'}
        </Button>
      </form>
    </AuthLayout>
  );
}
