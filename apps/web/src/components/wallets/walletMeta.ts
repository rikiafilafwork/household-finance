import { Banknote, Landmark, Smartphone, type LucideIcon } from 'lucide-react';
import type { WalletType } from '@/api/types';

export const walletTypeLabel: Record<WalletType, string> = { cash: 'Tunai', bank: 'Bank', ewallet: 'E-wallet' };
export const walletTypeIcon: Record<WalletType, LucideIcon> = { cash: Banknote, bank: Landmark, ewallet: Smartphone };
