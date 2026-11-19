import { useT } from '@/lib/i18n';
import type { ChromeLabel } from '@/lib/member-chrome';
import { cn } from '@/lib/utils';

// Stands in for the name so the translated words either side of it can be found in any word order.
const NAME = '\u{E000}';

/** Out of room, a label's `name` gives way and the words around it stay, since they say what the crumb leads to. */
export function CrumbText({ label, className }: { label: ChromeLabel | string; className?: string }) {
    const t = useT();
    const whole = typeof label === 'string' ? label : t(label.key, label.replacements);
    const name = typeof label === 'string' ? undefined : label.replacements?.name;
    const parts = typeof label !== 'string' && name !== undefined ? t(label.key, { ...label.replacements, name: NAME }).split(NAME) : [];

    if (parts.length !== 2) {
        return <span className={cn('min-w-0 truncate', className)}>{whole}</span>;
    }

    return (
        <span className={cn('flex min-w-0', className)}>
            <span className="sr-only">{whole}</span>
            {/* Flex items are blocks, which a screen reader may read as separate words. */}
            <span aria-hidden className="flex min-w-0">
                {parts[0] !== '' && <span className="shrink-0 whitespace-pre">{parts[0]}</span>}
                <span className="truncate">{String(name)}</span>
                {parts[1] !== '' && <span className="shrink-0 whitespace-pre">{parts[1]}</span>}
            </span>
        </span>
    );
}
