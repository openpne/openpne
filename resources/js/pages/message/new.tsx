import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { MessageMember } from './types';

/**
 * Who to write to, before there is a conversation to write in. Picking someone opens their
 * conversation — empty ones render with their composer — so nothing is written here and nothing is
 * submitted: the row is a link, not a choice to confirm.
 *
 * The search is the whole screen rather than a popup over a field, since choosing a person is the
 * only thing this screen does. What it may offer is RecipientCandidates: friends first, and the rest
 * of the site once there is a term to search by.
 */

/** Long enough that typing a name is one search, short enough that the list feels like it is following. */
const SEARCH_DEBOUNCE_MS = 200;

export default function MessageNew() {
    const t = useT();
    const [term, setTerm] = useState('');
    // Null until the first answer lands: an empty state before anything has been asked would tell the
    // member there is nobody to write to.
    const [candidates, setCandidates] = useState<MessageMember[] | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/messages/recipients?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
                .then((body: { candidates?: MessageMember[] }) => setCandidates(body.candidates ?? []))
                .catch(() => {
                    // A refused or failed search leaves the list empty and says nothing more than the
                    // empty state does: the member is looking for a name, not for an error.
                    if (!controller.signal.aborted) {
                        setCandidates([]);
                    }
                });
        }, SEARCH_DEBOUNCE_MS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    return (
        <>
            <Head title={t('New message')} />

            <Heading variant="pageCompose">{t('New message')}</Heading>

            {/* Card-less pill, the shape member search opens with — but no submit control: the list
                follows the field, so a magnifier would offer to do what has already happened. */}
            <div>
                <label htmlFor="recipient_search" className="sr-only">
                    {t('%nickname%')}
                </label>
                <Input
                    id="recipient_search"
                    type="search"
                    enterKeyHint="search"
                    autoComplete="off"
                    placeholder={t('Search by %nickname%')}
                    value={term}
                    onChange={(e) => setTerm(e.target.value)}
                    className="rounded-full px-5"
                />
            </div>

            {/* The list changes under a member who is typing rather than navigating, so how many
                names it now holds is announced. */}
            <p role="status" className="sr-only">
                {candidates === null ? '' : t(':count members found', { count: candidates.length })}
            </p>

            {candidates !== null &&
                (candidates.length === 0 ? (
                    <Panel>
                        <p className="text-sm text-muted-foreground">
                            {term.trim() === '' ? t('Search for a member to write to.') : t('No members found.')}
                        </p>
                    </Panel>
                ) : (
                    <Panel flush>
                        <List>
                            {candidates.map((candidate) => (
                                <ListRow key={candidate.id} rowLink chevron>
                                    <Avatar id={candidate.id} name={candidate.name} src={candidate.imageUrl} color={candidate.avatarColor} decorative />
                                    <p className="min-w-0 flex-1 truncate text-base text-foreground">
                                        <Link href={`/messages/${candidate.id}`} className={stretchedLink}>
                                            {candidate.name}
                                        </Link>
                                    </p>
                                </ListRow>
                            ))}
                        </List>
                    </Panel>
                ))}
        </>
    );
}
