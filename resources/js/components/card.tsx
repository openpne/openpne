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
                'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-800',
                className,
            )}
        >
            {children}
        </div>
    );
}

export function CardHeader({ children, className }: Props) {
    return <div className={cn('border-b border-slate-100 px-5 py-3 dark:border-slate-700', className)}>{children}</div>;
}

export function CardBody({ children, className }: Props) {
    return <div className={cn('px-5 py-4', className)}>{children}</div>;
}
