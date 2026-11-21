import { useMe, useLogout } from '@/auth/useAuth';
import { Button } from '@/components/ui/button';

// Sementara (#34). Diganti AppShell + onboarding + pemilih household di #35.
export function HomePlaceholder() {
  const { data: user } = useMe();
  const logout = useLogout();
  return (
    <main className="mx-auto flex min-h-dvh max-w-md flex-col items-center justify-center gap-4 p-6 text-center">
      <h1 className="text-2xl font-semibold">Halo, {user?.name}</h1>
      <Button variant="outline" onClick={() => logout.mutate()}>Keluar</Button>
    </main>
  );
}
