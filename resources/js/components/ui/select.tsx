import type { ComponentProps } from 'react';
import { BARE_BOX_CARETLESS, FIELD_BOX } from '@/components/ui/control-chrome';
import { cn } from '@/lib/utils';

/**
 * Token-based native select (keeps the platform picker + Inertia useForm compatibility; a Radix
 * combobox is heavier and unnecessary for these short option lists). `variant="bare"` drops the box
 * below sm but keeps the focus ring — a select has no caret to signal focus with.
 */
export function Select({ className, variant = 'field', ...props }: ComponentProps<'select'> & { variant?: 'field' | 'bare' }) {
    return <select className={cn('flex min-h-11 w-full', variant === 'bare' ? BARE_BOX_CARETLESS : FIELD_BOX, className)} {...props} />;
}
