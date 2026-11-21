import { ArrowLeftRight, LayoutDashboard, LogOut, MoreHorizontal, PiggyBank, Tags, Users, Wallet } from 'lucide-react';
import { useEffect } from 'react';
import { Navigate, NavLink, Outlet, useParams } from 'react-router';
import { useLogout, useMe } from '@/auth/useAuth';
import { FullPageSpinner } from '@/auth/guards';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { rememberHousehold, useHouseholds } from '@/hooks/useHouseholds';
import { cn } from '@/lib/utils';
import { Brand } from './AuthLayout';
import { HouseholdSwitcher } from './HouseholdSwitcher';

const NAV = [
  { to: '', label: 'Beranda', icon: LayoutDashboard, end: true },
  { to: 'transactions', label: 'Transaksi', icon: ArrowLeftRight },
  { to: 'budgets', label: 'Budget', icon: PiggyBank },
  { to: 'wallets', label: 'Wallet', icon: Wallet },
  { to: 'categories', label: 'Kategori', icon: Tags },
  { to: 'members', label: 'Anggota', icon: Users },
] as const;

/** Di HP: 4 menu utama di bawah + "Lainnya". */
const MOBILE_MAIN = NAV.slice(0, 4);
const MOBILE_MORE = NAV.slice(4);

function UserMenu() {
  const { data: user } = useMe();
  const logout = useLogout();
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="rounded-full" aria-label="Menu akun">
          <span className="flex size-8 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
            {user?.name.slice(0, 1).toUpperCase()}
          </span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="grid">
          <span className="truncate">{user?.name}</span>
          <span className="truncate text-xs font-normal text-muted-foreground">{user?.email}</span>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => logout.mutate()}>
          <LogOut className="size-4" /> Keluar
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export function AppShell() {
  const { householdId } = useParams();
  const { data: households, isPending } = useHouseholds();
  const id = Number(householdId);
  const household = households?.find((h) => h.id === id);

  useEffect(() => {
    if (household) rememberHousehold(household.id);
  }, [household]);

  if (isPending) return <FullPageSpinner />;
  // Bukan anggota (atau id ngawur): kembali ke household yang valid.
  if (!household) return <Navigate to="/" replace />;

  const base = `/h/${household.id}`;
  const linkTo = (to: string) => (to ? `${base}/${to}` : base);

  return (
    <div className="min-h-dvh bg-muted/30 md:grid md:grid-cols-[15rem_1fr]">
      <aside className="sticky top-0 hidden h-dvh flex-col gap-4 border-r bg-background p-3 md:flex">
        <div className="px-2 pt-1"><Brand /></div>
        <nav className="grid gap-1" aria-label="Menu utama">
          {NAV.map(({ to, label, icon: Icon, ...rest }) => (
            <NavLink
              key={to}
              to={linkTo(to)}
              end={'end' in rest}
              className={({ isActive }) =>
                cn('flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-foreground', isActive && 'bg-accent text-foreground')
              }
            >
              <Icon className="size-4" /> {label}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-col">
        <header className="sticky top-0 z-30 flex h-14 items-center justify-between gap-2 border-b bg-background/95 px-3 backdrop-blur md:px-6">
          <HouseholdSwitcher />
          <div className="flex items-center gap-1">
            <div id="notification-bell-slot" />
            <UserMenu />
          </div>
        </header>
        <main className="mx-auto w-full max-w-6xl flex-1 p-4 pb-24 md:p-6 md:pb-6">
          <Outlet />
        </main>
      </div>

      <nav className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t bg-background pb-[env(safe-area-inset-bottom)] md:hidden" aria-label="Menu utama">
        {MOBILE_MAIN.map(({ to, label, icon: Icon, ...rest }) => (
          <NavLink
            key={to}
            to={linkTo(to)}
            end={'end' in rest}
            className={({ isActive }) => cn('flex flex-col items-center gap-0.5 py-2 text-[11px] text-muted-foreground', isActive && 'text-primary')}
          >
            <Icon className="size-5" /> {label}
          </NavLink>
        ))}
        <DropdownMenu>
          <DropdownMenuTrigger className="flex flex-col items-center gap-0.5 py-2 text-[11px] text-muted-foreground">
            <MoreHorizontal className="size-5" /> Lainnya
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" side="top" className="w-48">
            {MOBILE_MORE.map(({ to, label, icon: Icon }) => (
              <DropdownMenuItem key={to} asChild>
                <NavLink to={linkTo(to)}><Icon className="size-4" /> {label}</NavLink>
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </nav>
    </div>
  );
}
