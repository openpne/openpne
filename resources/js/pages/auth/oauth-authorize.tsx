import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { CheckboxField, Field, FormActions, RadioCardGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioCard } from '@/components/ui/radio-card';
import { Select } from '@/components/ui/select';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

type AiAccountRef = { id: number; name: string; isAi: boolean };

type Props = {
    client: { name: string; host: string };
    actors: { aiAccounts: AiAccountRef[]; canCreate: boolean };
    authToken: string;
    csrfToken: string;
};

const NEW = 'new';

// Two native forms rather than useForm: both answers are a redirect to the client's own origin,
// which the page must follow as a navigation.
export default function OAuthAuthorize({ client, actors, authToken, csrfToken }: Props) {
    const t = useT();
    const { flash, errors } = usePage<PageProps>().props;
    const hasAccounts = actors.aiAccounts.length > 0;
    const offersAi = hasAccounts || actors.canCreate;
    const [kind, setKind] = useState<'ai' | 'self'>(offersAi ? 'ai' : 'self');
    const [account, setAccount] = useState<string>(hasAccounts ? `ai:${actors.aiAccounts[0]?.id}` : NEW);
    const [submitting, setSubmitting] = useState(false);
    const actor = kind === 'self' ? 'self' : account;
    const title = t('Connect :host', { host: client.host });

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

            <p className="text-sm text-muted-foreground">{t('“:name” is asking to connect.', { name: client.name })}</p>

            <form id="consent-deny" method="post" action="/oauth/authorize" onSubmit={() => setSubmitting(true)}>
                <input type="hidden" name="_token" value={csrfToken} />
                <input type="hidden" name="_method" value="DELETE" />
                <input type="hidden" name="auth_token" value={authToken} />
            </form>

            <form method="post" action="/oauth/authorize" className="space-y-4" onSubmit={() => setSubmitting(true)}>
                <input type="hidden" name="_token" value={csrfToken} />
                <input type="hidden" name="auth_token" value={authToken} />
                <input type="hidden" name="actor" value={actor} />

                {offersAi && (
                    <RadioCardGroup legend={t('Who takes part?')} error={errors.actor}>
                        <RadioCard
                            name="kind"
                            value="ai"
                            checked={kind === 'ai'}
                            onChange={() => setKind('ai')}
                            label={t('As an AI account')}
                            description={t('A separate account marked as AI takes part. Posts appear under its name.')}
                        />
                        {kind === 'ai' && (
                            <div className="space-y-3 pl-7">
                                {hasAccounts && (
                                    <Field label={t('AI account')} htmlFor="account">
                                        <Select id="account" value={account} onChange={(e) => setAccount(e.target.value)}>
                                            {actors.aiAccounts.map((a) => (
                                                <option key={a.id} value={`ai:${a.id}`}>
                                                    {a.name}
                                                </option>
                                            ))}
                                            {actors.canCreate && <option value={NEW}>{t('Create a new one')}</option>}
                                        </Select>
                                    </Field>
                                )}
                                {account === NEW && (
                                    <Field label={t('Name')} htmlFor="name" error={errors.name} required>
                                        <Input id="name" name="name" maxLength={255} defaultValue="" />
                                    </Field>
                                )}
                            </div>
                        )}
                        <RadioCard
                            name="kind"
                            value="self"
                            checked={kind === 'self'}
                            onChange={() => setKind('self')}
                            label={t('As yourself')}
                            description={t('Reads and writes under your name. What the AI writes is yours.')}
                        />
                    </RadioCardGroup>
                )}

                {!offersAi && <p className="text-sm text-foreground">{t('Reads and writes under your name. What the AI writes is yours.')}</p>}

                <CheckboxField name="read_only" value="1" label={t('Read-only: it can read but not post')} />

                <FormActions className="justify-end">
                    <Button type="submit" form="consent-deny" variant="outline" disabled={submitting}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={submitting}>
                        {t('Connect')}
                    </Button>
                </FormActions>
            </form>
        </AuthLayout>
    );
}
