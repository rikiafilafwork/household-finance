import { Navigate, Outlet, useLocation, useSearchParams } from 'react-router';
import { safeNext, withNext } from '@/lib/redirect';
import { useMe } from './useAuth';

export function FullPageSpinner() {
  return (
    <div className="flex min-h-dvh items-center justify-center" role="status" aria-label="Memuat">
      <div className="size-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
    </div>
  );
}

/** Halaman aplikasi: wajib login. Belum login → /login?next=<halaman ini>. */
export function RequireAuth() {
  const { data: user, isPending } = useMe();
  const location = useLocation();

  if (isPending) return <FullPageSpinner />;
  if (!user) return <Navigate to={withNext('/login', location.pathname + location.search)} replace />;
  return <Outlet />;
}

/** Login/daftar: kalau sudah login, langsung ke tujuan. */
export function GuestOnly() {
  const { data: user, isPending } = useMe();
  const [params] = useSearchParams();

  if (isPending) return <FullPageSpinner />;
  if (user) return <Navigate to={safeNext(params.get('next'))} replace />;
  return <Outlet />;
}
