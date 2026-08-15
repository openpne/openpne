import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Spinner } from '@/components/spinner';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import type { TalkReactorGroup } from './types';

/**
 * Who reacted to one message. The names live nowhere in the page's own payload — they are the one
 * part of a reaction that grows with the room — so they are read when this opens and only then.
 *
 * The read is bounded at the server: an emoji's count is exact, its names stop at a hundred, and the
 * rest are a number. A refusal closes the dialog without a word: the message was deleted, or the
 * reader may no longer read the group, and neither is something to explain over a chip row.
 */
export function TalkReactorsDialog({ url, onClose }: { url: string; onClose: () => void }) {
    const t = useT();
    const [groups, setGroups] = useState<TalkReactorGroup[] | null>(null);

    useEffect(() => {
        const controller = new AbortController();

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then((response) => (response.ok ? (response.json() as Promise<{ groups?: TalkReactorGroup[] }>) : Promise.reject(new Error(String(response.status)))))
            .then((payload) => setGroups(payload.groups ?? []))
            .catch(() => {
                if (!controller.signal.aborted) {
                    onClose();
                }
            });

        return () => controller.abort();
    }, [url, onClose]);

    return (
        <Dialog open onOpenChange={(next) => !next && onClose()}>
            <DialogContent closeLabel={t('Close')} aria-describedby={undefined} className="max-h-[70vh] overflow-y-auto">
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
