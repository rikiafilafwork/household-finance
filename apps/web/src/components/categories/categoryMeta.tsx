import {
  Baby, BookOpen, Briefcase, Bus, Car, CircleEllipsis, Coffee, Dumbbell, Fuel, Gamepad2, Gift, GraduationCap, HandCoins,
  HeartPulse, House, Laptop, PawPrint, Plane, Popcorn, Receipt, Shirt, ShoppingBag, ShoppingCart, Smartphone, Sparkles,
  Tag, TrendingUp, Utensils, Wallet, Wifi, Wrench, Zap, type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Category } from '@/api/types';

/**
 * Ikon kategori = nama ikon lucide (kebab-case) yang disimpan server. Web hanya memuat daftar
 * terkurasi ini (tanpa memuat seluruh lucide); nama lain jatuh ke ikon Tag.
 */
export const CATEGORY_ICONS: Record<string, LucideIcon> = {
  utensils: Utensils, coffee: Coffee, 'shopping-cart': ShoppingCart, 'shopping-bag': ShoppingBag, car: Car, bus: Bus,
  fuel: Fuel, plane: Plane, receipt: Receipt, zap: Zap, wifi: Wifi, smartphone: Smartphone, house: House, wrench: Wrench,
  'heart-pulse': HeartPulse, dumbbell: Dumbbell, 'graduation-cap': GraduationCap, 'book-open': BookOpen, baby: Baby,
  'paw-print': PawPrint, shirt: Shirt, sparkles: Sparkles, popcorn: Popcorn, 'gamepad-2': Gamepad2, gift: Gift,
  'hand-coins': HandCoins, wallet: Wallet, laptop: Laptop, briefcase: Briefcase, 'trending-up': TrendingUp,
  'circle-ellipsis': CircleEllipsis, tag: Tag,
};

export const CATEGORY_COLORS = [
  '#F97316', '#EAB308', '#16A34A', '#14B8A6', '#0EA5E9', '#3B82F6', '#8B5CF6', '#EC4899', '#EF4444', '#9CA3AF',
] as const;

export function CategoryIcon({ category, className }: { category: Pick<Category, 'color' | 'icon'>; className?: string }) {
  const Icon = (category.icon && CATEGORY_ICONS[category.icon]) || Tag;
  const color = category.color ?? '#9CA3AF';
  return (
    <span
      aria-hidden
      className={cn('flex size-8 shrink-0 items-center justify-center rounded-full', className)}
      style={{ backgroundColor: `${color}22`, color }}
    >
      <Icon className="size-4" />
    </span>
  );
}
