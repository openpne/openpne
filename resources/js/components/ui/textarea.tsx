import type { ComponentProps } from 'react';
import { BARE_BOX, FIELD_BOX } from '@/components/ui/control-chrome';
import { cn } from '@/lib/utils';

/**
 * Token-based multiline input. Set `aria-invalid` to surface the error ring. Pass `variant="bare"`
 * inside a `FormRow` so the box drops below sm and the text aligns on the row's inset.
 */
export function Textarea({ className, variant = 'field', ...props }: ComponentProps<'textarea'> & { variant?: 'field' | 'bare' }) {
    return (
        <textarea
            className={cn('flex min-h-24 w-full placeholder:text-muted-foreground', variant === 'bare' ? BARE_BOX : FIELD_BOX, className)}
            {...props}
        />
    );
}
