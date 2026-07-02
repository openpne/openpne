import type { ReactNode } from 'react';

export type FlashVariant = 'success' | 'error';

// A colored left border + tint carries the status hue; the body stays text-foreground so contrast is
// AA in both modes (a mid success/destructive color as text on its own /10 tint fails AA).
const variantClass: Record<FlashVariant, string> = {
    success: 'border-success/30 border-l-4 border-l-success bg-success/10',
    error: 'border-destructive/30 border-l-4 border-l-destructive bg-destructive/10',
};

type Props = {
    children: ReactNode;
    variant?: FlashVariant;
};

export function FlashMessage({ children, variant = 'success' }: Props) {
    return (
        <div
            className={`rounded-md border px-4 py-2 text-sm text-foreground ${variantClass[variant]}`}
            role={variant === 'error' ? 'alert' : 'status'}
        >
            {children}
        </div>
    );
}
