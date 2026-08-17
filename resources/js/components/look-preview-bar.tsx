import { router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * The bar a member keeps or drops a look from. A look changes the whole shell, so it is tried on
 * before it is saved: the choice sits in the session (App\Support\LookResolver) and this bar is the
 * only way out of that state — which is why it stands on every page, not just the settings one, and
 * why it is in normal flow at the top of the frame rather than floating over the page furniture the
 * member is here to judge (the bottom bar, a compose sheet).
 *
 * Both buttons are real POSTs: the server answers with a full page load, since what changes is the
 * shell the SPA is running inside.
 */
export function LookPreviewBar() {
    const t = useT();
    const { lookPreview } = usePage<PageProps>().props;

    if (lookPreview === null) {
        return null;
    }

    return (
        <div
            role="region"
            aria-label={t('Layout preview')}
            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-border bg-muted px-3 py-2 sm:px-4"
        >
            {/* The two intents confirm to opposite writes (pin the look / go back to following the
                site), so the bar says which one is on the table, not just what is being rendered. */}
            <p className="text-sm text-foreground">
                {lookPreview.pin
                    ? t('Previewing: :look', { look: t(lookPreview.label) })
                    : t('Previewing the site default (currently :look)', { look: t(lookPreview.label) })}
            </p>
            {/* Default size, not sm: both are decisions taken on a phone, so they keep the 44px target. */}
            <div className="flex items-center gap-2">
                <Button onClick={() => router.post('/member/config/look')}>{t('Use this layout')}</Button>
                <Button variant="outline" onClick={() => router.post('/member/config/look/preview/stop')}>
                    {t('Cancel')}
                </Button>
            </div>
        </div>
    );
}
