import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { rememberHousehold, useCurrentHousehold, useHouseholds } from '@/hooks/useHouseholds';

export function HouseholdSwitcher() {
  const current = useCurrentHousehold();
  const { data: households = [] } = useHouseholds();
  const navigate = useNavigate();

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" className="h-auto max-w-56 justify-between gap-2 px-2 py-1.5" aria-label="Ganti household">
          <span className="grid text-left leading-tight">
            <span className="truncate font-semibold">{current.name}</span>
            <span className="text-xs text-muted-foreground">{current.role === 'owner' ? 'Owner' : 'Anggota'}</span>
          </span>
          <ChevronsUpDown className="size-4 shrink-0 opacity-60" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-64">
        <DropdownMenuLabel className="text-xs text-muted-foreground">Household</DropdownMenuLabel>
        {households.map((h) => (
          <DropdownMenuItem
            key={h.id}
            onSelect={() => {
              rememberHousehold(h.id);
              navigate(`/h/${h.id}`);
            }}
          >
            <span className="flex-1 truncate">{h.name}</span>
            {h.id === current.id && <Check className="size-4" />}
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => navigate('/onboarding')}>
          <Plus className="size-4" /> Buat household baru
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
