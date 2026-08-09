import { usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { isIosNotInstalled, permissionState, subscribeThisDevice } from '@/lib/push';
import type { PageProps } from '@/types';

// Mirrors color-mode's persisted flag: a dismissal survives reloads. Guarded reads/writes because a
// privacy-locked localStorage throws on access.
const STORAGE_KEY = 'openpne-push-prompt';

function isDismissed(): boolean {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * A dismissible invitation to turn on push, page-owned so it renders under the Notifications heading
 * (not a FlashMessage — the member frame owns that). Shows only where push is offered, the browser
 * has not been asked yet, and the member has not dismissed it. Enabling runs from the button's own
 * click so the native permission prompt is always a direct response to a tap.
 */
export function PushPrompt() {
    const t = useT();
    const push = usePage<PageProps>().props.push;
    const [hidden, setHidden] = useState(isDismissed);

    if (!push || hidden || permissionState() !== 'default') {
        return null;
    }

    const dismiss = () => {
        try {
            window.localStorage.setItem(STORAGE_KEY, '1');
        } catch {
            // A blocked localStorage just means the prompt returns next load; nothing else breaks.
        }
        setHidden(true);
    };

    const enable = async () => {
        // Either way the browser leaves the 'default' state, so the prompt stops matching and hides.
        await subscribeThisDevice(push.vapidPublicKey);
        setHidden(true);
    };

    return (
        <div className="flex items-start gap-3 rounded-md border border-primary/30 border-l-4 border-l-primary bg-primary/10 px-4 py-3 text-sm text-foreground">
            <div className="min-w-0 flex-1 space-y-2">
                <p>{t('Turn on push notifications to know when something new arrives, even when this site is closed.')}</p>
                {isIosNotInstalled() ? (
                    <p className="text-muted-foreground">{t('To get push notifications on iPhone or iPad, add this site to your Home Screen first.')}</p>
                ) : (
                    <Button size="sm" onClick={enable}>
                        {t('Enable')}
                    </Button>
                )}
            </div>
            <button
                type="button"
                onClick={dismiss}
                aria-label={t('Dismiss')}
                className="-mr-1 -mt-1 shrink-0 rounded p-1 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <X className="size-4" aria-hidden />
            </button>
        </div>
    );
}
