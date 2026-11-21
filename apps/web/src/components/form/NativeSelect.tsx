import { forwardRef, type ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/** <select> bawaan bergaya input: pemilih asli di HP lebih nyaman daripada popover. */
export const NativeSelect = forwardRef<HTMLSelectElement, ComponentProps<'select'>>(function NativeSelect({ className, ...props }, ref) {
  return (
    <select
      ref={ref}
      className={cn(
        'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30',
        className,
      )}
      {...props}
    />
  );
});
