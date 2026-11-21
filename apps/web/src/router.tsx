import { createBrowserRouter } from 'react-router';
import { onUnauthorized } from '@/api/client';
import { qk } from '@/api/keys';
import { GuestOnly, RequireAuth } from '@/auth/guards';
import { queryClient } from '@/lib/query-client';
import { withNext } from '@/lib/redirect';
import { AcceptInvitationPage } from '@/pages/auth/AcceptInvitationPage';
import { LoginPage } from '@/pages/auth/LoginPage';
import { RegisterPage } from '@/pages/auth/RegisterPage';
import { HomePlaceholder } from '@/pages/HomePlaceholder';

export const router = createBrowserRouter([
  {
    element: <GuestOnly />,
    children: [
      { path: '/login', element: <LoginPage /> },
      { path: '/register', element: <RegisterPage /> },
    ],
  },
  { path: '/invitations/:token', element: <AcceptInvitationPage /> },
  {
    element: <RequireAuth />,
    children: [{ path: '*', element: <HomePlaceholder /> }],
  },
]);

// 401 di request mana pun (sesi habis): lupakan user, ke login, lalu kembali ke halaman ini.
onUnauthorized(() => {
  const { pathname, search } = router.state.location;
  if (pathname === '/login' || pathname === '/register') return;
  queryClient.setQueryData(qk.me, null);
  void router.navigate(withNext('/login', pathname + search), { replace: true });
});
