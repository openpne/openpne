import type { ComponentProps } from 'react';
import { Select } from '@/components/ui/select';
import { useT } from '@/lib/i18n';

/**
 * A compact min-height (28px, which still clears WCAG 2.2 SC 2.5.8's 24px) rather than the 44px
 * input target, and a min-height rather than a fixed one so it grows with zoom and text spacing.
 * Option labels are translation keys.
 */
export function VisibilitySelect({
    options,
    ...props
}: Omit<ComponentProps<'select'>, 'children' | 'className'> & { options: Array<{ value: number; label: string }> }) {
    const t = useT();

    return (
        <Select className="min-h-7 w-auto shrink-0 px-2 py-0.5 text-sm shadow-none" {...props}>
            {options.map((opt) => (
                <option key={opt.value} value={opt.value}>{t(opt.label)}</option>
            ))}
        </Select>
    );
}
