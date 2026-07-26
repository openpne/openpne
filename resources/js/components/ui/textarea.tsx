import type { ComponentProps } from 'react';
import { BARE_BOX, FIELD_BOX } from '@/components/ui/control-chrome';
import { cn } from '@/lib/utils';

/**
 * Token-based multiline input. Set `aria-invalid` to surface the error ring. `variant="bare"` is the
 * compose body's edge-to-edge editing surface below sm — see control-chrome; ordinary fields keep the
 * default box.
 */
export function Textarea({ className, variant = 'field', ...props }: ComponentProps<'textarea'> & { variant?: 'field' | 'bare' }) {
    return (
        <textarea
            className={cn('flex min-h-24 w-full placeholder:text-muted-foreground', variant === 'bare' ? BARE_BOX : FIELD_BOX, className)}
            {...props}
        />
    );
}
