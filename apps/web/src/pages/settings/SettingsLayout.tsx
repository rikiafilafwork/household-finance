import { NavLink, Outlet } from 'react-router';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/utils';

const TABS = [
  { to: '/settings/categories', label: 'Kategori' },
  { to: '/settings/templates', label: 'Template bulanan' },
  { to: '/settings/profile', label: 'Profil' },
] as const;

export function SettingsLayout() {
  return (
    <>
      <PageHeader title="Pengaturan" />
      <nav aria-label="Pengaturan" className="mb-4 flex gap-1 overflow-x-auto border-b">
        {TABS.map(({ to, label }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) => cn(
              '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap',
              isActive ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
          >
            {label}
          </NavLink>
        ))}
      </nav>
      <Outlet />
    </>
  );
}
