import { Head, Link, usePage } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { Timestamp } from '@/components/timestamp';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { ConversationRow } from './conversation-row';
import type { MessageRow, PaginatedConversations, PaginatedMessages } from '../types';

interface ConversationsProps extends PageProps {
    conversations: PaginatedConversations;
    /** The mailbox's drafts, which belong to no conversation. */
    drafts: PaginatedMessages;
}

/** The drafts pager's own query parameter, so paging one list never moves the other. */
const DRAFT_PAGE = 'draft_page';

export default function MessageConversations() {
    const t = useT();
    const { conversations, drafts } = usePage<ConversationsProps>().props;

    return (
        <>
            <Head title={t('Messages')} />

            {conversations.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No conversations yet.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {conversations.data.map((conversation) => (
                                <ConversationRow key={conversation.counterpart?.id ?? 'withdrawn'} conversation={conversation} />
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={conversations.meta} />
                </>
            )}

            {/* A draft has no receipt, so it is in neither arm of any conversation and would be
                unreachable without a place of its own. */}
            {drafts.data.length > 0 && (
                <>
                    <Panel flush title={t('Drafts')}>
                        <List>
                            {drafts.data.map((draft) => (
                                <DraftRow key={draft.id} draft={draft} />
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={drafts.meta} pageName={DRAFT_PAGE} />
                </>
            )}
        </>
    );
}

function DraftRow({ draft }: { draft: MessageRow }) {
    const t = useT();
    const name = draft.counterparty?.name ?? t('Withdrawn member');

    return (
        <ListRow rowLink chevron className="items-start">
            <Avatar
                id={draft.counterparty?.id ?? 0}
                name={name}
                src={draft.counterparty?.imageUrl ?? null}
                color={draft.counterparty?.avatarColor ?? null}
                isAi={draft.counterparty?.isAi ?? false}
                decorative
            />
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-1.5">
                    <p className="min-w-0 truncate text-base text-foreground">
                        <Link href={`/message/edit/${draft.id}`} className={stretchedLink}>
                            {name}
                        </Link>
                    </p>
                    <AiChip isAi={draft.counterparty?.isAi ?? false} />
                </div>
                <p className="mt-0.5 truncate text-sm text-muted-foreground">{draft.subject || t('(No subject)')}</p>
            </div>
            <Timestamp at={draft.date} preset="relative" className="shrink-0 text-xs text-muted-foreground" />
        </ListRow>
    );
}
