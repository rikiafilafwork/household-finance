import { Banknote, Landmark, Smartphone, type LucideIcon } from 'lucide-react';
import type { AccountType } from '@/api/types';

export const accountTypeLabel: Record<AccountType, string> = { bank: 'Rekening', ewallet: 'E-wallet', cash: 'Tunai' };
export const accountTypeIcon: Record<AccountType, LucideIcon> = { bank: Landmark, ewallet: Smartphone, cash: Banknote };
