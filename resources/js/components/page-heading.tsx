import type { ReactNode } from 'react';
import { Heading } from '@/components/ui/heading';
import { cn } from '@/lib/utils';

type Props = {
    title: ReactNode;
    action?: ReactNode;
    className?: string;
};

export function PageHeading({ title, action, className }: Props) {
    return (
        <div className={cn('flex min-h-11 items-center justify-between gap-3', className)}>
            <Heading variant="page" className="min-w-0">
                {title}
            </Heading>
            {action && <div className="hidden shrink-0 lg:block">{action}</div>}
        </div>
    );
}
