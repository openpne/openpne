import { router } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import { useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import { useT } from '@/lib/i18n';

/**
 * Per-group quiet, sitting above the conversation as a small text action rather than in the page
 * heading — the heading is the chrome's, shared by every group subpage.
 *
 * It states the state it is moving to, not a bare flip, so a double tap settles instead of racing.
 * On success the page reloads only this prop: the nav badge is the shell's, and its own refresh is
 * what corrects it.
 */
export function TalkMuteToggle({ groupId, muted }: { groupId: number; muted: boolean }) {
    const t = useT();
    const [saving, setSaving] = useState(false);
    const Icon = muted ? BellOff : Bell;

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
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="flex justify-end">
            <button
                type="button"
                onClick={toggle}
                disabled={saving}
                aria-pressed={muted}
                className="inline-flex items-center gap-1 rounded-md text-sm text-link outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
            >
                <Icon className="size-4" aria-hidden />
                {muted ? t('Unmute') : t('Mute')}
            </button>
        </div>
    );
}
