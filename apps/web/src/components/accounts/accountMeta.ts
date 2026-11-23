import { Banknote, Landmark, Smartphone, type LucideIcon } from 'lucide-react';
import type { AccountType } from '@/api/types';

export const ACCOUNT_TYPES: AccountType[] = ['bank', 'ewallet', 'cash'];
export const accountTypeLabel: Record<AccountType, string> = { bank: 'Rekening', ewallet: 'E-wallet', cash: 'Tunai' };
export const accountTypeGroupLabel: Record<AccountType, string> = { bank: 'Rekening', ewallet: 'E-wallet', cash: 'Dompet' };
export const accountTypeIcon: Record<AccountType, LucideIcon> = { bank: Landmark, ewallet: Smartphone, cash: Banknote };
