import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { coverGradientStops } from './identity-visual';

export function IdentityCover({ hex, as: Tag = 'div', className, children }: { hex: string; as?: 'div' | 'span'; className?: string; children: ReactNode }) {
    const cover = coverGradientStops(hex);

    return (
        <Tag
            aria-hidden
            className={cn('bg-linear-135 from-(--cover-from) to-(--cover-to)', className)}
            style={{ '--cover-from': cover.from, '--cover-to': cover.to } as CSSProperties}
        >
            {children}
        </Tag>
    );
}
