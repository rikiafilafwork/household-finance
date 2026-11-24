import { createBrowserRouter, Navigate } from 'react-router';
import { onUnauthorized } from '@/api/client';
import { qk } from '@/api/keys';
import { GuestOnly, RequireAuth } from '@/auth/guards';
import { AppShell } from '@/components/layout/AppShell';
import { queryClient } from '@/lib/query-client';
import { withNext } from '@/lib/redirect';
import { LoginPage } from '@/pages/auth/LoginPage';
import { RegisterPage } from '@/pages/auth/RegisterPage';
import { AccountsPage } from '@/pages/AccountsPage';
import { DashboardPage } from '@/pages/DashboardPage';
import { PlanPage } from '@/pages/PlanPage';
import { CategoriesPage } from '@/pages/settings/CategoriesPage';
import { ProfilePage } from '@/pages/settings/ProfilePage';
import { TemplatesPage } from '@/pages/settings/TemplatesPage';
import { SettingsLayout } from '@/pages/settings/SettingsLayout';
import { TransactionsPage } from '@/pages/TransactionsPage';

/** Rute datar: satu user = satu pemilik data (tanpa /h/:householdId). */
export const router = createBrowserRouter([
  {
    element: <GuestOnly />,
    children: [
      { path: '/login', element: <LoginPage /> },
      { path: '/register', element: <RegisterPage /> },
    ],
  },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppShell />,
        children: [
          { path: '/', element: <DashboardPage /> },
          { path: '/plan', element: <PlanPage /> },
          { path: '/transactions', element: <TransactionsPage /> },
          { path: '/accounts', element: <AccountsPage /> },
          { path: '/settings', element: <Navigate to="/settings/categories" replace /> },
          {
            element: <SettingsLayout />,
            children: [
              { path: '/settings/categories', element: <CategoriesPage /> },
              { path: '/settings/templates', element: <TemplatesPage /> },
              { path: '/settings/profile', element: <ProfilePage /> },
            ],
          },
        ],
      },
      { path: '*', element: <Navigate to="/" replace /> },
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
