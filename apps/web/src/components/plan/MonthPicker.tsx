import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { formatMonth, shiftMonth } from '@/lib/dates';

const MONTH = /^\d{4}-(0[1-9]|1[0-2])$/;

/**
 * Bulan yang dipilih dari `?month=YYYY-MM` (dipakai Rencana dan Beranda). Bulan berjalan tidak
 * ditulis ke URL; nilai yang tidak valid diabaikan.
 */
export function useMonthParam(current: string) {
  const [params, setParams] = useSearchParams();
  const requested = params.get('month');
  const month = requested && MONTH.test(requested) ? requested : current;
  const setMonth = (m: string) => setParams(m === current ? {} : { month: m });
  return [month, setMonth] as const;
}

export function MonthPicker({ month, current, onChange }: { month: string; current: string; onChange: (month: string) => void }) {
  return (
    <nav aria-label="Pilih bulan" className="mb-4 flex items-center gap-1">
      <Button variant="ghost" size="icon" aria-label="Bulan sebelumnya" onClick={() => onChange(shiftMonth(month, -1))}><ChevronLeft /></Button>
      <h2 className="min-w-40 text-center text-base font-semibold" aria-live="polite">{formatMonth(month)}</h2>
      <Button variant="ghost" size="icon" aria-label="Bulan berikutnya" onClick={() => onChange(shiftMonth(month, 1))}><ChevronRight /></Button>
      {month !== current && <Button variant="link" size="sm" onClick={() => onChange(current)}>Bulan ini</Button>}
    </nav>
  );
}
