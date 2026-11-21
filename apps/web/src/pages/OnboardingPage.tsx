import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { Link, useNavigate } from 'react-router';
import { z } from 'zod';
import { householdsApi } from '@/api/households';
import { qk } from '@/api/keys';
import type { Household } from '@/api/types';
import { useLogout, useMe } from '@/auth/useAuth';
import { Field, FormMessage } from '@/components/form/Field';
import { AuthLayout } from '@/components/layout/AuthLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { applyServerErrors } from '@/lib/form-errors';
import { rememberHousehold, useHouseholds } from '@/hooks/useHouseholds';
import { useState } from 'react';

const schema = z.object({ name: z.string().trim().min(1, 'Nama household wajib diisi.').max(100, 'Maksimal 100 karakter.') });

/**
 * Buat household baru: dipakai saat user belum punya household (mis. keluar dari household
 * terakhir) dan dari pemilih household ("Buat household baru").
 */
export function OnboardingPage() {
  const { data: user } = useMe();
  const { data: households } = useHouseholds();
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const logout = useLogout();
  const [message, setMessage] = useState<string | null>(null);
  const first = (households?.length ?? 0) === 0;

  const form = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema),
    defaultValues: { name: first && user ? `Keluarga ${user.name.split(' ')[0]}` : '' },
  });

  const create = useMutation({
    mutationFn: householdsApi.create,
    onSuccess: (household) => {
      queryClient.setQueryData<Household[]>(qk.households, (old) => [...(old ?? []), household].sort((a, b) => a.name.localeCompare(b.name)));
      rememberHousehold(household.id);
      navigate(`/h/${household.id}`, { replace: true });
    },
  });

  const onSubmit = form.handleSubmit(async (values) => {
    setMessage(null);
    try {
      await create.mutateAsync(values);
    } catch (error) {
      setMessage(applyServerErrors(error, form.setError, ['name']));
    }
  });

  return (
    <AuthLayout
      title={first ? 'Buat household pertama' : 'Buat household baru'}
      description="Household = satu keluarga/rumah: wallet, kategori, dan budget dipakai bersama anggotanya. Kategori umum langsung disiapkan."
      footer={first ? (
        <button type="button" className="underline-offset-4 hover:underline" onClick={() => logout.mutate()}>Keluar</button>
      ) : (
        <Link to="/" className="underline-offset-4 hover:underline">Batal</Link>
      )}
    >
      <form onSubmit={onSubmit} noValidate className="grid gap-4">
        <FormMessage message={message} />
        <Field label="Nama household" htmlFor="name" error={form.formState.errors.name?.message}>
          <Input id="name" autoFocus aria-invalid={!!form.formState.errors.name} {...form.register('name')} />
        </Field>
        <Button type="submit" disabled={form.formState.isSubmitting} className="w-full">
          {form.formState.isSubmitting ? 'Membuat…' : 'Buat household'}
        </Button>
        {first && <p className="text-xs text-muted-foreground">Diundang pasangan? Buka link undangan di email Anda untuk bergabung ke household yang sudah ada.</p>}
      </form>
    </AuthLayout>
  );
}
