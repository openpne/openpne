import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    children: ReactNode;
    className?: string;
};

/** Rounded card wrapping a block of page content. */
export function Card({ children, className }: Props) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-border bg-card text-card-foreground shadow-sm',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function CardHeader({ children, className }: Props) {
    return <div className={cn('border-b border-border px-5 py-3', className)}>{children}</div>;
}

export function CardBody({ children, className }: Props) {
    return <div className={cn('px-6 py-5', className)}>{children}</div>;
}
