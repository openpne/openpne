import { router } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import { useT } from '@/lib/i18n';
import { requestUnreadRefresh } from '@/lib/unread-refresh';

const SPOKEN_MS = 3000;

/**
 * The state to move to is posted, not a flip, so a double tap settles (docs/internals/group-talk.md,
 * "Mute"). Both directions also get a spoken line, since whether a changed description is re-read
 * differs by screen reader.
 */
export function TalkMuteToggle({ groupId, muted }: { groupId: number; muted: boolean }) {
    const t = useT();
    const [saving, setSaving] = useState(false);
    const [spoken, setSpoken] = useState<string | null>(null);
    const spokenTimer = useRef<number | null>(null);
    const Icon = muted ? BellOff : Bell;
    const explainerId = `talk-mute-explainer-${groupId}`;

    // A line still pending when the room is left must not set state on an unmounted control.
    useEffect(() => () => {
        if (spokenTimer.current !== null) {
            window.clearTimeout(spokenTimer.current);
        }
    }, []);

    const speak = (line: string) => {
        if (spokenTimer.current !== null) {
            window.clearTimeout(spokenTimer.current);
        }
        setSpoken(line);
        spokenTimer.current = window.setTimeout(() => {
            setSpoken(null);
            spokenTimer.current = null;
        }, SPOKEN_MS);
    };

    const toggle = async () => {
        if (saving) {
            return;
        }
        setSaving(true);
        try {
            const response = await fetch(`/groups/${groupId}/talk/mute`, {
                method: 'POST',
                headers: { ...xsrfHeader(), 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ muted: !muted }),
            });

            if (response.ok) {
                router.reload({ only: ['isMuted'] });
                requestUnreadRefresh();
                speak(muted ? t('Unmuted.') : t('Muted.'));
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-1">
            <div className="flex justify-end">
                <button
                    type="button"
                    onClick={toggle}
                    disabled={saving}
                    aria-pressed={muted}
                    aria-describedby={muted ? explainerId : undefined}
                    className="inline-flex items-center gap-1 rounded-md text-sm text-link outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                >
                    <Icon className="size-4" aria-hidden />
                    {muted ? t('Unmute') : t('Mute')}
                </button>
            </div>
            {muted && (
                <p id={explainerId} className="text-sm text-muted-foreground">
                    {t('Muted: new-message notifications are off and this %community% is not counted in the badge. You are still notified when mentioned.')}
                </p>
            )}
            {/* In the tree whether or not it has words, so the change is what is announced. */}
            <p aria-live="polite" className="text-sm text-muted-foreground">
                {spoken}
            </p>
        </div>
    );
}
