import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { useConfirm } from '@/components/confirm-dialog';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, FormActions } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
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

interface TokenRow {
    id: number;
    readOnly: boolean;
    createdAt: string;
    lastUsedAt: string | null;
}

interface Tokens {
    tokens: TokenRow[];
    /** Whether the account password is due again (the re-auth window has lapsed). */
    requiresPassword: boolean;
    /** False while an administrator has the MCP endpoint switched off; tokens stay manageable. */
    mcpEnabled: boolean;
    /** The credential just minted, on this render only. */
    newToken: string | null;
}

interface SelfIntroduction {
    /** The field's own caption — an operator may have renamed it, so it is not a fixed string here. */
    label: string;
    value: string;
    maxLength: number | null;
}

interface AiShowProps extends PageProps {
    account: MemberRef;
    /** Null while the install has no self-introduction field: there is nowhere to save one. */
    selfIntroduction: SelfIntroduction | null;
    /** Absent while the %community% unit is switched off — there is nothing to join or leave. */
    groups?: Groups;
    tokens: Tokens;
}

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

/**
 * Shown on the one render the credential exists, so it reads as a moment to act on rather than page
 * furniture that would still be here next visit.
 */
function NewToken({ value }: { value: string }) {
    const t = useT();

    return (
        <section className="space-y-2 rounded-md border-2 border-dashed border-foreground/30 p-4">
            <Heading as="h3" variant="section">
                {t('Your new access token')}
            </Heading>
            <p className="flex items-start gap-1.5 text-sm text-foreground">
                {/* Inherits the text color rather than taking --warning, which is a fill token and
                    reads under the 3:1 a meaningful glyph needs on a light card. */}
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                {t('This token is shown only this once.')}
            </p>
            <p className="text-sm text-muted-foreground">
                {t('Put it into your MCP client now. It cannot be shown again — a lost token is replaced, not recovered.')}
            </p>
            <code className="block break-all text-sm select-all">{value}</code>
        </section>
    );
}

/** Three writes, so three posts: a file upload is its own submit. */
function IdentityPanel({ account, selfIntroduction }: { account: MemberRef; selfIntroduction: SelfIntroduction | null }) {
    const t = useT();
    const identity = useForm({ name: account.name, self_introduction: selfIntroduction?.value ?? '' });
    const image = useForm<{ image: File | null }>({ image: null });
    const removeImage = useForm({});

    return (
        <Panel title={t('Profile')} bodyClassName="space-y-5">
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

            <form
                onSubmit={(e: FormEvent<HTMLFormElement>) => {
                    e.preventDefault();
                    image.post(`/member/config/ai/${account.id}/avatar`, { preserveScroll: true, onSuccess: () => image.reset() });
                }}
                className="space-y-3"
            >
                <Field label={t('Profile image')} htmlFor="ai_avatar" error={image.errors.image}>
                    <input
                        id="ai_avatar"
                        type="file"
                        name="image"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        onChange={(e) => image.setData('image', e.target.files?.[0] ?? null)}
                        required
                        className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:text-secondary-foreground hover:file:bg-secondary/80"
                    />
                </Field>
                <FormActions>
                    <Button type="submit" variant="outline" size="sm" loading={image.processing}>
                        {t('Upload')}
                    </Button>
                    {account.imageUrl && (
                        // A button, not a second form: forms do not nest, and the post is a script's
                        // anyway.
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            loading={removeImage.processing}
                            onClick={() => removeImage.post(`/member/config/ai/${account.id}/avatar/delete`, { preserveScroll: true })}
                        >
                            {t('Remove')}
                        </Button>
                    )}
                </FormActions>
            </form>

            <form
                onSubmit={(e: FormEvent<HTMLFormElement>) => {
                    e.preventDefault();
                    identity.post(`/member/config/ai/${account.id}`, { preserveScroll: true });
                }}
                className="space-y-4 border-t border-border pt-5"
            >
                <Field label={t('Name')} htmlFor="ai_identity_name" error={identity.errors.name}>
                    <Input
                        id="ai_identity_name"
                        type="text"
                        maxLength={255}
                        required
                        value={identity.data.name}
                        onChange={(e) => identity.setData('name', e.target.value)}
                    />
                </Field>
                {selfIntroduction && (
                    <Field label={selfIntroduction.label} htmlFor="ai_self_introduction" error={identity.errors.self_introduction}>
                        <Textarea
                            id="ai_self_introduction"
                            maxLength={selfIntroduction.maxLength ?? undefined}
                            value={identity.data.self_introduction}
                            onChange={(e) => identity.setData('self_introduction', e.target.value)}
                        />
                    </Field>
                )}
                <FormActions>
                    <Button type="submit" loading={identity.processing}>
                        {t('Save')}
                    </Button>
                </FormActions>
            </form>
        </Panel>
    );
}

/**
 * The issue button and every revoke button post the same form, so the password is asked for once per
 * panel rather than once per row, and its error has one place to appear.
 */
function TokenPanel({ account, tokens }: { account: MemberRef; tokens: Tokens }) {
    const t = useT();
    const confirm = useConfirm();
    const { absolute } = useDateFormat();
    const form = useForm({ current_password: '', read_only: false });
    const [busy, setBusy] = useState<string | null>(null);

    // No onSuccess reset of the password: useForm's callbacks close over the data as it was at
    // submit time, so resetting from one would quietly undo a choice made after it.
    const submit = (url: string, key: string) =>
        form.post(url, {
            preserveScroll: true,
            onStart: () => setBusy(key),
            onFinish: () => setBusy(null),
        });

    const revoke = async (id: number) => {
        if (
            await confirm({
                title: t('Revoke this token?'),
                description: t('Any MCP client still using it stops working at once.'),
                confirmLabel: t('Revoke'),
                danger: true,
            })
        ) {
            submit(`/member/config/ai/${account.id}/tokens/${id}/delete`, `revoke-${id}`);
        }
    };

    return (
        <Panel title={t('Access tokens')}>
            <form
                onSubmit={(e: FormEvent<HTMLFormElement>) => {
                    e.preventDefault();
                    submit(`/member/config/ai/${account.id}/tokens`, 'issue');
                }}
                className="space-y-5"
            >
                {tokens.newToken && <NewToken value={tokens.newToken} />}

                <p className="text-sm text-muted-foreground">
                    {t('A token lets an MCP client take part in this site as this AI account, reaching exactly what it reaches.')}
                    {!tokens.mcpEnabled && ` ${t('This site has the MCP endpoint switched off, so a token cannot be used until an administrator turns it back on.')}`}
                </p>

                {tokens.tokens.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('This AI account has no tokens.')}</p>
                ) : (
                    <ul className="divide-y divide-border">
                        {tokens.tokens.map((token) => (
                            <li key={token.id} className="flex items-center gap-3 py-3 first:pt-0">
                                <div className="min-w-0 flex-1">
                                    {/* The name is the same on every one of these, so what tells them
                                        apart is the reach and whether anything has used it. */}
                                    <p className="text-sm text-foreground">{token.readOnly ? t('Read-only') : t('Read and write')}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('Issued :date', { date: absolute(token.createdAt) })}
                                        {' · '}
                                        {token.lastUsedAt ? t('Last used :date', { date: absolute(token.lastUsedAt) }) : t('Never used')}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    loading={busy === `revoke-${token.id}`}
                                    onClick={() => revoke(token.id)}
                                >
                                    {t('Revoke')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}

                {/* The password sits with the controls it guards; its help names revoking too, since
                    the buttons above post this same field. */}
                <div className="space-y-4 border-t border-border pt-5">
                    {tokens.requiresPassword && (
                        <Field
                            label={t('Current password')}
                            htmlFor="ai_token_password"
                            help={t('Issuing or revoking a token asks for your password.')}
                            error={form.errors.current_password}
                        >
                            <Input
                                id="ai_token_password"
                                type="password"
                                autoComplete="current-password"
                                value={form.data.current_password}
                                onChange={(e) => form.setData('current_password', e.target.value)}
                            />
                        </Field>
                    )}
                    <label className="flex items-center gap-2 text-sm text-foreground">
                        <Checkbox checked={form.data.read_only} onChange={(e) => form.setData('read_only', e.target.checked)} />
                        {t('Read-only: it can read but not post')}
                    </label>
                    <FormActions>
                        <Button type="submit" loading={busy === 'issue'}>
                            {t('Issue a token')}
                        </Button>
                    </FormActions>
                </div>
            </form>
        </Panel>
    );
}

export default function AiAccountShow() {
    const t = useT();
    const confirm = useConfirm();
    const { account, selfIntroduction, groups, tokens } = usePage<AiShowProps>().props;
    const [keyword, setKeyword] = useState(groups?.keyword ?? '');
    const [searching, setSearching] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const deleteForm = useForm({ password: '' });

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

    const destroy = async (e: FormEvent) => {
        e.preventDefault();

        if (
            await confirm({
                title: t('Delete :name?', { name: account.name }),
                description: t('This cannot be undone. What it posted stays on the site, shown as by a withdrawn member.'),
                confirmLabel: t('Delete'),
                danger: true,
            })
        ) {
            // Nothing to clear on success — that response leaves this page — so the reset is for the
            // refusals, which re-render the form with whatever was typed still in it.
            deleteForm.post(`/member/config/ai/${account.id}/delete`, { onError: () => deleteForm.reset('password') });
        }
    };

    return (
        <>
            <Head title={account.name} />
            <Heading variant="page">{account.name}</Heading>

            <IdentityPanel account={account} selfIntroduction={selfIntroduction} />

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

            <TokenPanel account={account} tokens={tokens} />

            <Panel className="border-destructive/40" title={t('Delete this AI account')}>
                <form onSubmit={destroy} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {t('Deleting is permanent. What it posted stays on the site, shown as by a withdrawn member.')}
                    </p>
                    <Field label={t('Current password')} htmlFor="ai_delete_password" error={deleteForm.errors.password}>
                        <Input
                            id="ai_delete_password"
                            type="password"
                            autoComplete="current-password"
                            value={deleteForm.data.password}
                            onChange={(e) => deleteForm.setData('password', e.target.value)}
                        />
                    </Field>
                    <FormActions>
                        <Button type="submit" variant="destructive" loading={deleteForm.processing}>
                            {t('Delete')}
                        </Button>
                    </FormActions>
                </form>
            </Panel>
        </>
    );
}
