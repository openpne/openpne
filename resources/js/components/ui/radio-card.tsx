import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<'input'> & {
    label: ReactNode;
    description?: ReactNode;
};

export function RadioCard({ label, description, className, ...props }: Props) {
    return (
        <label
            className={cn(
                // Selection is shown by the selected-token border + ring (not a background tint), so the
                // muted description keeps its contrast on the card surface (AA) in both light and dark.
                'flex cursor-pointer items-start gap-3 rounded-field border border-input p-4 transition-colors hover:border-selected/50 has-[:checked]:border-selected has-[:checked]:ring-1 has-[:checked]:ring-selected has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                className,
            )}
        >
            <input type="radio" className="mt-0.5 size-4 shrink-0 accent-selected outline-none" {...props} />
            <span className="text-sm">
                <span className="text-foreground">{label}</span>
                {description && <span className="mt-0.5 block text-muted-foreground">{description}</span>}
            </span>
        </label>
    );
}
