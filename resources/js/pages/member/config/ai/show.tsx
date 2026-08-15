import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { useConfirm } from '@/components/confirm-dialog';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { Button } from '@/components/ui/button';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { MemberRef } from '@/pages/community/types';
import type { PageProps } from '@/types';

interface GroupRow {
    id: number;
    name: string;
    memberCount: number;
    imageUrl: string | null;
    /** Present on the browse rows only (GroupSerializer::detail): 'open' | 'approval'. */
    registerPolicy?: string;
}

interface Groups {
    joined: GroupRow[];
    pending: GroupRow[];
    browse: { data: GroupRow[]; meta: PaginationMeta };
    joinedIds: number[];
    pendingIds: number[];
    keyword: string;
}

interface AiShowProps extends PageProps {
    account: MemberRef;
    /** Absent while the %community% unit is switched off — there is nothing to join or leave. */
    groups?: Groups;
}

/** One group row: image, name linking to the group, member count, and whatever this page can do to it. */
function GroupRowItem({ group, action }: { group: GroupRow; action: React.ReactNode }) {
    const t = useT();

    return (
        <ListRow>
            <CommunityImage name={group.name} src={group.imageUrl} className="size-10" decorative />
            <div className="min-w-0 flex-1">
                <p className="min-w-0 truncate text-foreground">
                    <Link href={`/groups/${group.id}`} className="hover:underline">
                        {group.name}
                    </Link>
                </p>
                <p className="text-xs text-muted-foreground">
                    {t(':count members', { count: group.memberCount })}
                    {/* Stated, not only implied by the button's verb: what "apply" leads to is a wait
                        for someone else, and the row should say so before it is pressed. */}
                    {group.registerPolicy === 'approval' && (
                        <>
                            {' · '}
                            {t('Approval required')}
                        </>
                    )}
                </p>
            </div>
            {action}
        </ListRow>
    );
}

export default function AiAccountShow() {
    const t = useT();
    const confirm = useConfirm();
    const { account, groups } = usePage<AiShowProps>().props;
    const [keyword, setKeyword] = useState(groups?.keyword ?? '');
    const [searching, setSearching] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    const post = (url: string, key: string) =>
        router.post(url, {}, { preserveScroll: true, onStart: () => setBusy(key), onFinish: () => setBusy(null) });

    const search = (e: FormEvent) => {
        e.preventDefault();
        router.get(`/member/config/ai/${account.id}`, { keyword: keyword || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    const destroy = async () => {
        if (
            await confirm({
                title: t('Delete :name?', { name: account.name }),
                description: t('This cannot be undone. What it posted stays on the site, shown as by a withdrawn member.'),
                confirmLabel: t('Delete'),
                danger: true,
            })
        ) {
            router.post(`/member/config/ai/${account.id}/delete`);
        }
    };

    return (
        <>
            <Head title={account.name} />
            <Heading variant="page">{account.name}</Heading>

            <Panel>
                <div className="flex items-center gap-3">
                    <Avatar id={account.id} name={account.name} src={account.imageUrl} color={account.avatarColor} isAi={account.isAi} size="lg" decorative />
                    <div className="min-w-0 flex-1">
                        <p className="flex min-w-0 items-center gap-1.5">
                            <span className="min-w-0 truncate text-foreground">{account.name}</span>
                            <AiChip isAi={account.isAi} />
                        </p>
                        <p className="text-sm">
                            <Link href={`/member/${account.id}`} className="text-link hover:underline">
                                {t('View profile')}
                            </Link>
                        </p>
                    </div>
                </div>
            </Panel>

            {groups && (
                <>
                    <Panel flush title={t('Joined %communities%')}>
                        {groups.joined.length === 0 ? (
                            <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">
                                {t('This AI account has not joined any %communities%.')}
                            </p>
                        ) : (
                            <List>
                                {groups.joined.map((group) => (
                                    <GroupRowItem
                                        key={group.id}
                                        group={group}
                                        action={
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                loading={busy === `quit-${group.id}`}
                                                onClick={() => post(`/member/config/ai/${account.id}/groups/${group.id}/quit`, `quit-${group.id}`)}
                                            >
                                                {t('Leave')}
                                            </Button>
                                        }
                                    />
                                ))}
                            </List>
                        )}
                    </Panel>

                    {groups.pending.length > 0 && (
                        <Panel flush title={t('Awaiting approval')}>
                            <List>
                                {groups.pending.map((group) => (
                                    <GroupRowItem
                                        key={group.id}
                                        group={group}
                                        action={
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                loading={busy === `cancel-${group.id}`}
                                                onClick={() => post(`/member/config/ai/${account.id}/groups/${group.id}/cancel`, `cancel-${group.id}`)}
                                            >
                                                {t('Cancel request')}
                                            </Button>
                                        }
                                    />
                                ))}
                            </List>
                        </Panel>
                    )}

                    <div className="space-y-3">
                        <form onSubmit={search} className="relative">
                            <label htmlFor="ai_group_keyword" className="sr-only">
                                {t('Search by %community% name')}
                            </label>
                            <Input
                                id="ai_group_keyword"
                                type="search"
                                enterKeyHint="search"
                                placeholder={t('Search by %community% name')}
                                value={keyword}
                                onChange={(e) => setKeyword(e.target.value)}
                                className="rounded-full pr-11 pl-5"
                            />
                            <SearchSubmitButton loading={searching} />
                        </form>

                        <Panel flush title={t('Join a %community%')}>
                            {groups.browse.data.length === 0 ? (
                                <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('No %communities% found.')}</p>
                            ) : (
                                <List>
                                    {groups.browse.data.map((group) => {
                                        const joined = groups.joinedIds.includes(group.id);
                                        const pending = groups.pendingIds.includes(group.id);
                                        const approval = group.registerPolicy === 'approval';

                                        return (
                                            <GroupRowItem
                                                key={group.id}
                                                group={group}
                                                action={
                                                    // Every row states where this account stands with the
                                                    // group, so the browse list stays the whole catalogue
                                                    // instead of a filtered one whose pager lies about it.
                                                    joined || pending ? (
                                                        <span className="shrink-0 text-sm text-muted-foreground">
                                                            {joined ? t('Already joined') : t('Awaiting approval')}
                                                        </span>
                                                    ) : (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            loading={busy === `join-${group.id}`}
                                                            onClick={() => post(`/member/config/ai/${account.id}/groups/${group.id}/join`, `join-${group.id}`)}
                                                        >
                                                            {/* Approval groups say "apply", because that is
                                                                what the button does — the seat comes later,
                                                                from an admin. */}
                                                            {approval ? t('Apply to join') : t('Join')}
                                                        </Button>
                                                    )
                                                }
                                            />
                                        );
                                    })}
                                </List>
                            )}
                        </Panel>
                        <Pagination meta={groups.browse.meta} />
                    </div>
                </>
            )}

            <Panel className="border-destructive/40" title={t('Delete this AI account')}>
                <div className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        {t('Deleting is permanent. What it posted stays on the site, shown as by a withdrawn member.')}
                    </p>
                    <Button variant="destructive" onClick={destroy}>
                        {t('Delete')}
                    </Button>
                </div>
            </Panel>
        </>
    );
}
