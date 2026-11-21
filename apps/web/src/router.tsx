import { createBrowserRouter } from 'react-router';
import { onUnauthorized } from '@/api/client';
import { qk } from '@/api/keys';
import { GuestOnly, RequireAuth } from '@/auth/guards';
import { AppShell } from '@/components/layout/AppShell';
import { queryClient } from '@/lib/query-client';
import { withNext } from '@/lib/redirect';
import { AcceptInvitationPage } from '@/pages/auth/AcceptInvitationPage';
import { LoginPage } from '@/pages/auth/LoginPage';
import { RegisterPage } from '@/pages/auth/RegisterPage';
import { ComingSoon } from '@/pages/ComingSoon';
import { DashboardPage } from '@/pages/DashboardPage';
import { HomeRedirect } from '@/pages/HomeRedirect';
import { OnboardingPage } from '@/pages/OnboardingPage';
import { TransactionsPage } from '@/pages/TransactionsPage';

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
    children: [
      { path: '/', element: <HomeRedirect /> },
      { path: '/onboarding', element: <OnboardingPage /> },
      {
        path: '/h/:householdId',
        element: <AppShell />,
        children: [
          { index: true, element: <DashboardPage /> },
          { path: 'transactions', element: <TransactionsPage /> },
          { path: 'wallets', element: <ComingSoon title="Wallet" issue={38} /> },
          { path: 'categories', element: <ComingSoon title="Kategori" issue={38} /> },
          { path: 'budgets', element: <ComingSoon title="Budget" issue={39} /> },
          { path: 'members', element: <ComingSoon title="Anggota & undangan" issue={40} /> },
        ],
      },
      { path: '*', element: <HomeRedirect /> },
    ],
  },
]);

// 401 di request mana pun (sesi habis): lupakan user, ke login, lalu kembali ke halaman ini.
onUnauthorized(() => {
  const { pathname, search } = router.state.location;
  if (pathname === '/login' || pathname === '/register') return;
  queryClient.setQueryData(qk.me, null);
  void router.navigate(withNext('/login', pathname + search), { replace: true });
});
