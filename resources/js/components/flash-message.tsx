import type { ReactNode } from 'react';

export type FlashVariant = 'success' | 'error';

const variantClass: Record<FlashVariant, string> = {
    success:
        'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200',
    error: 'border-red-200 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200',
};

type Props = {
    children: ReactNode;
    variant?: FlashVariant;
};

export function FlashMessage({ children, variant = 'success' }: Props) {
    return (
        <div className={`rounded-lg border px-4 py-2 text-sm ${variantClass[variant]}`} role="status">
            {children}
        </div>
    );
}
