import { usePage } from '@inertiajs/react';
import { PlaceSwitch } from '@/components/place-switch';
import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import type { Header } from '@/lib/member-chrome';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/** The phone header's middle and the desk place bar draw the name of where the reader is through this alone (docs/internals/modern-frame.md, "The phone header"). */
export function HeaderName({ header, variant }: { header: Exclude<Header, { type: 'none' }>; variant: 'phone' | 'desk' }) {
    const t = useT();
    const { props } = usePage<PageProps>();

    if (header.type === 'place' && header.pressable) {
        return <PlaceSwitch scope={header.scope} variant={variant} />;
    }

    const text =
        header.type === 'brand'
            ? props.name
            : header.type === 'place'
              ? header.scope.name
              : typeof header.label === 'string'
                ? header.label
                : t(header.label.key, header.label.replacements);

    return (
        // A hub's own h1 stays in the page, so this copy would be a second announcement of the same words.
        <span
            aria-hidden={(header.type === 'title' && header.hub) || undefined}
            className={cn(headingVariants({ variant: 'bar' }), 'min-w-0 truncate', variant === 'phone' ? 'px-1 text-center' : 'h-8 leading-8')}
        >
            {text}
        </span>
    );
}
