import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Spinner } from '@/components/spinner';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import type { ReactorGroup } from '@/lib/reactions/types';

/** A refusal closes the dialog without a word (docs/internals/reactions.md, "Reading"). */
export function ReactorsDialog({
    url,
    emoji,
    returnFocusTo,
    onClose,
}: {
    url: string;
    /** The chip it was asked from, listed first. */
    emoji?: string;
    /** Given when what asked is gone by the time this mounts, as a sheet's item is; a chip that asked holds focus itself. */
    returnFocusTo?: HTMLElement | null;
    onClose: () => void;
}) {
    const t = useT();
    const [groups, setGroups] = useState<ReactorGroup[] | null>(null);
    // Opened from a menu rather than a trigger of its own, so the dialog names its own way back: what held focus as it mounted.
    const [opener] = useState(() => returnFocusTo ?? (document.activeElement instanceof HTMLElement ? document.activeElement : null));

    useEffect(() => {
        const controller = new AbortController();

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then((response) => (response.ok ? (response.json() as Promise<{ groups?: ReactorGroup[] }>) : Promise.reject(new Error(String(response.status)))))
            .then((payload) => setGroups(leadWith(payload.groups ?? [], emoji)))
            .catch(() => {
                if (!controller.signal.aborted) {
                    onClose();
                }
            });

        return () => controller.abort();
    }, [url, emoji, onClose]);

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                closeLabel={t('Close')}
                aria-describedby={undefined}
                className="max-h-[70vh] overflow-y-auto"
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    opener?.focus({ preventScroll: true });
                }}
            >
                <DialogTitle className={headingVariants({ variant: 'section' })}>{t('Reactions')}</DialogTitle>
                {groups === null ? (
                    <p className="flex justify-center py-6 text-muted-foreground">
                        <Spinner size={6} />
                    </p>
                ) : (
                    <div className="mt-3 space-y-4">
                        {groups.map((group) => (
                            <section key={group.emoji}>
                                <p className="flex items-center gap-1 text-sm text-muted-foreground">
                                    <span className="text-lg">{group.emoji}</span>
                                    <span className="tabular-nums">{group.count}</span>
                                </p>
                                <ul className="mt-1 space-y-1">
                                    {group.members.map((member) => (
                                        <li key={member.id} className="flex items-center gap-2">
                                            <Avatar id={member.id} name={member.name} src={member.imageUrl} color={member.avatarColor} isAi={member.isAi} size="sm" decorative />
                                            <Link href={`/member/${member.id}`} className="truncate text-link hover:underline">
                                                {member.name}
                                            </Link>
                                            <AiChip isAi={member.isAi} />
                                        </li>
                                    ))}
                                </ul>
                                {group.count > group.members.length && (
                                    <p className="mt-1 text-sm text-muted-foreground">{t('and :count more', { count: group.count - group.members.length })}</p>
                                )}
                            </section>
                        ))}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function leadWith(groups: ReactorGroup[], emoji: string | undefined): ReactorGroup[] {
    const lead = groups.find((group) => group.emoji === emoji);

    return lead === undefined ? groups : [lead, ...groups.filter((group) => group !== lead)];
}
