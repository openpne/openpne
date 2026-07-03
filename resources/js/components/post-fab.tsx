import { Link, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * A page is a compose/edit/settings form when its path carries one of these verbs. The FAB — a
 * shortcut to *start* a diary — is out of place on such a page: it invites abandoning a half-filled
 * form and floats over the form's own controls. Fragments (not full paths) so new form routes under
 * the same verbs need no upkeep here.
 */
const FORM_URL_FRAGMENTS = ['/new', '/edit', '/config', '/sendToFriend', '/reply/'];

/**
 * Mobile (< lg) primary action: a floating "write a diary" button. The desktop sidebar carries the
 * same action as a pill, so this is hidden at lg+. Hidden on compose/edit/settings pages (see above).
 */
export function PostFab() {
    const t = useT();
    const { url, props } = usePage<PageProps>();

    if (!props.auth.user) {
        return null;
    }
    if (FORM_URL_FRAGMENTS.some((fragment) => url.includes(fragment))) {
        return null;
    }

    // The nav landmark keeps this action inside a region (axe) and carries the fixed positioning, so
    // no blurred/transformed ancestor becomes its containing block.
    return (
        <nav
            aria-label={t('Post %diary%')}
            className="fixed right-5 bottom-[calc(1.25rem+env(safe-area-inset-bottom))] z-30 lg:hidden"
        >
            <Link
                href="/m/diary/new"
                aria-label={t('Post %diary%')}
                className="inline-flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background active:scale-[0.97]"
            >
                <Pencil className="size-6" strokeWidth={2.25} />
            </Link>
        </nav>
    );
}
