import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { householdsApi } from '@/api/households';
import { qk } from '@/api/keys';
import type { Household } from '@/api/types';

const LAST_KEY = 'hf:last-household';

export function useHouseholds() {
  return useQuery({ queryKey: qk.households, queryFn: householdsApi.list, staleTime: 5 * 60_000 });
}

export function rememberHousehold(id: number) {
  try {
    localStorage.setItem(LAST_KEY, String(id));
  } catch {
    /* mode privat: abaikan */
  }
}

export function lastHouseholdId(): number | null {
  try {
    const value = Number(localStorage.getItem(LAST_KEY));
    return Number.isInteger(value) && value > 0 ? value : null;
  } catch {
    return null;
  }
}

/** Household aktif dari URL `/h/:householdId`. Hanya dipakai di bawah AppShell. */
export function useCurrentHousehold(): Household {
  const { householdId } = useParams();
  const { data } = useHouseholds();
  const household = data?.find((h) => h.id === Number(householdId));
  if (!household) throw new Error('useCurrentHousehold dipakai di luar AppShell');
  return household;
}

export function useIsOwner() {
  return useCurrentHousehold().role === 'owner';
}
