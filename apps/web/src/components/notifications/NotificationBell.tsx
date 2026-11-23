import { AlertTriangle, Bell, CheckCheck, Info } from 'lucide-react';
import { useNavigate } from 'react-router';
import type { AppNotification } from '@/api/types';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useNotificationMutations, useNotifications } from '@/hooks/useNotifications';
import { cn } from '@/lib/utils';
import { describeNotification, timeAgo, type NotificationTone } from './notificationMeta';

const toneIcon: Record<NotificationTone, { icon: typeof Bell; className: string }> = {
  warning: { icon: AlertTriangle, className: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' },
  over: { icon: AlertTriangle, className: 'bg-destructive/10 text-destructive' },
  info: { icon: Info, className: 'bg-muted text-muted-foreground' },
};

function NotificationRow({ notification, onOpen }: { notification: AppNotification; onOpen: () => void }) {
  const view = describeNotification(notification);
  const unread = notification.read_at === null;
  const { icon: Icon, className } = toneIcon[view.tone];

  return (
    <DropdownMenuItem
      onSelect={onOpen}
      data-testid={`notification-${notification.id}`}
      data-unread={unread || undefined}
      className={cn('items-start gap-3 px-3 py-2.5', unread && 'bg-primary/5')}
    >
      <span className={cn('mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full', className)}><Icon className="size-3.5" /></span>
      <span className="grid min-w-0 flex-1 gap-0.5">
        <span className={cn('text-sm', unread ? 'font-semibold' : 'font-medium')}>{view.title}</span>
        {view.body && <span className="text-xs whitespace-normal text-muted-foreground">{view.body}</span>}
        <span className="text-[11px] text-muted-foreground">{timeAgo(notification.created_at)}</span>
      </span>
      {unread && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary" aria-label="Belum dibaca" />}
    </DropdownMenuItem>
  );
}

/** Lonceng in-app (Keputusan #10): alert amplop & reconcile. Klik = tandai dibaca + buka halamannya. */
export function NotificationBell() {
  const navigate = useNavigate();
  const { data, isPending, isError } = useNotifications();
  const m = useNotificationMutations();
  const unread = data?.meta.unread_count ?? 0;
  const items = data?.data ?? [];

  const open = (n: AppNotification) => {
    if (n.read_at === null) m.read.mutate(n.id);
    navigate(describeNotification(n).href);
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="relative rounded-full" aria-label={unread > 0 ? `Notifikasi, ${unread} belum dibaca` : 'Notifikasi'}>
          <Bell className="size-5" />
          {unread > 0 && (
            <span data-testid="notification-count" className="absolute top-0.5 right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-none font-semibold text-white">
              {unread > 9 ? '9+' : unread}
            </span>
          )}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-[min(22rem,calc(100vw-1.5rem))] p-0">
        <div className="flex items-center justify-between gap-2 px-3 py-2">
          <DropdownMenuLabel className="p-0">Notifikasi</DropdownMenuLabel>
          {unread > 0 && (
            <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={() => m.readAll.mutate()} disabled={m.readAll.isPending}>
              <CheckCheck className="size-3.5" /> Tandai semua dibaca
            </Button>
          )}
        </div>
        <DropdownMenuSeparator className="my-0" />
        <div className="max-h-[min(26rem,70dvh)] overflow-y-auto py-1">
          {isPending && <p className="px-3 py-6 text-center text-sm text-muted-foreground">Memuat…</p>}
          {isError && <p className="px-3 py-6 text-center text-sm text-muted-foreground">Notifikasi gagal dimuat.</p>}
          {!isPending && !isError && items.length === 0 && (
            <p className="px-3 py-6 text-center text-sm text-muted-foreground">Belum ada notifikasi. Alert muncul saat amplop terpakai 80% dan 100%.</p>
          )}
          {items.map((n) => <NotificationRow key={n.id} notification={n} onOpen={() => open(n)} />)}
        </div>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
