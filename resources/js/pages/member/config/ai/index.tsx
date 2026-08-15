import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Button } from '@/components/ui/button';
import { Field, FormActions } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface AiAccount {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
    groupCount: number;
}

interface AiIndexProps extends PageProps {
    accounts: AiAccount[];
    used: number;
    limit: number;
    /** Whether the site is offering creation at all — false still lists and links what is owned. */
    enabled: boolean;
    canCreate: boolean;
}

export default function AiAccountIndex() {
    const t = useT();
    const { accounts, used, limit, enabled, canCreate } = usePage<AiIndexProps>().props;
    const form = useForm({ name: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/member/config/ai');
    };

    return (
        <>
            <Head title={t('AI accounts')} />
            <Heading variant="page">{t('AI accounts')}</Heading>

            <Panel
                flush
                title={t('Your AI accounts')}
                right={<span className="shrink-0 text-sm text-muted-foreground">{t(':used of :limit used', { used, limit })}</span>}
            >
                {accounts.length === 0 ? (
                    <p className="px-4 py-4 text-sm text-muted-foreground sm:px-5">{t('You have no AI accounts.')}</p>
                ) : (
                    <List>
                        {accounts.map((account) => (
                            <ListRow key={account.id} rowLink chevron>
                                <Avatar
                                    id={account.id}
                                    name={account.name}
                                    src={account.imageUrl}
                                    color={account.avatarColor}
                                    isAi={account.isAi}
                                    size="md"
                                    decorative
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="flex min-w-0 items-center gap-1.5">
                                        <Link href={`/member/config/ai/${account.id}`} className={cn('min-w-0 truncate text-foreground', stretchedLink)}>
                                            {account.name}
                                        </Link>
                                        <AiChip isAi={account.isAi} />
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('In :count %communities%', { count: account.groupCount })}
                                    </p>
                                </div>
                            </ListRow>
                        ))}
                    </List>
                )}
            </Panel>

            <Panel title={t('Create an AI account')}>
                {canCreate ? (
                    <form onSubmit={submit} className="space-y-4">
                        <Field
                            label={t('Name')}
                            htmlFor="ai_name"
                            help={t('It appears on this site as a member marked AI. It cannot sign in — you speak for it.')}
                            error={form.errors.name}
                        >
                            <Input
                                id="ai_name"
                                type="text"
                                maxLength={255}
                                required
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>
                        <FormActions>
                            <Button type="submit" loading={form.processing}>
                                {t('Create')}
                            </Button>
                        </FormActions>
                    </form>
                ) : (
                    // Two different dead ends, and which one it is decides what to do about it: wait
                    // for the operator, or delete one of your own.
                    <p className="text-sm text-muted-foreground">
                        {enabled
                            ? t('You already have as many AI accounts as this site allows.')
                            : t('This site is not offering new AI accounts right now. The ones you already have keep working.')}
                    </p>
                )}
            </Panel>
        </>
    );
}
