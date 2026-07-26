import type { ComponentProps } from 'react';
import { bareBoxFor, FIELD_BOX } from '@/components/ui/control-chrome';
import { cn } from '@/lib/utils';

/**
 * Token-based text input. Set `aria-invalid` to surface the error ring. Pass `variant="bare"` inside
 * a `FormRow` so the box drops below sm and the text aligns on the row's inset — see control-chrome.
 */
export function Input({ className, variant = 'field', type, ...props }: ComponentProps<'input'> & { variant?: 'field' | 'bare' }) {
    return (
        <input
            type={type}
            className={cn(
                'flex min-h-11 w-full placeholder:text-muted-foreground',
                variant === 'bare' ? bareBoxFor(type) : FIELD_BOX,
                className,
            )}
            {...props}
        />
    );
}
