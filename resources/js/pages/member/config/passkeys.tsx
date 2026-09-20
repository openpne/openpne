import { router, useForm } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { Field, FormActions, FormSection } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { OtpInput } from '@/components/ui/otp-input';
import { useT } from '@/lib/i18n';
import { PASSKEY_ROUTES, passkeyErrorKey } from '@/lib/passkeys';
import { useDateFormat } from '@/lib/use-date-format';

interface PasskeyRow {
    id: number;
    name: string;
    authenticator: string | null;
    synced: boolean;
    createdAt: string | null;
    lastUsedAt: string | null;
}

interface Props {
    passkeys: PasskeyRow[];
    requiresPassword: boolean;
    requiresSecondFactor: boolean;
    deviceBoundOnly: boolean;
}

function Explanation() {
    const t = useT();

    return (
        <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
            <li>{t('A passkey signs you in instead of your password.')}</li>
            <li>{t('You unlock it with your device\'s face, fingerprint or PIN.')}</li>
            <li>{t('Your biometric data never leaves your device; this site only stores a public key.')}</li>
        </ul>
    );
}

/** Password (+ second factor) first, so a wrong proof fails before the browser prompt. */
function Reauth({ requiresSecondFactor }: { requiresSecondFactor: boolean }) {
    const t = useT();
    const form = useForm({ current_password: '', code: '', recovery_code: '' });
    const [useRecovery, setUseRecovery] = useState(false);

    function toggleRecovery() {
        form.resetAndClearErrors('code', 'recovery_code');
        setUseRecovery((v) => !v);
    }

    return (
        <form
            onSubmit={(e: FormEvent<HTMLFormElement>) => {
                e.preventDefault();
                form.post('/member/config/passkeys/reauth');
            }}
            className="space-y-4"
        >
            <p className="text-sm text-foreground">{t('To add a passkey, first confirm it is you.')}</p>
            <Field label={t('Current password')} htmlFor="current_password" error={form.errors.current_password}>
                <Input
                    id="current_password"
                    type="password"
                    autoComplete="current-password"
                    value={form.data.current_password}
                    onChange={(e) => form.setData('current_password', e.target.value)}
                />
            </Field>
            {requiresSecondFactor &&
                (useRecovery ? (
                    <Field
                        label={t('Recovery code')}
                        htmlFor="recovery_code"
                        help={t('Each recovery code can be used once, if you no longer have your authenticator.')}
                        error={form.errors.recovery_code}
                    >
                        <Input
                            id="recovery_code"
                            type="text"
                            autoComplete="off"
                            value={form.data.recovery_code}
                            onChange={(e) => form.setData('recovery_code', e.target.value)}
                        />
                    </Field>
                ) : (
                    <Field
                        label={t('Authentication code')}
                        htmlFor="code"
                        help={t('A passkey signs you in without this code, so adding one needs it once.')}
                        error={form.errors.code}
                    >
                        <OtpInput label={t('Authentication code')} value={form.data.code} onChange={(code) => form.setData('code', code)} />
                    </Field>
                ))}
            {requiresSecondFactor && (
                <p className="text-sm">
                    <button type="button" onClick={toggleRecovery} className="text-link hover:underline">
                        {useRecovery ? t('Use an authentication code instead') : t('Use a recovery code instead')}
                    </button>
                </p>
            )}
            <FormActions>
                <Button type="submit" loading={form.processing}>
                    {t('Continue')}
                </Button>
            </FormActions>
        </form>
    );
}

function Register() {
    const t = useT();
    const [name, setName] = useState('');
    const [failure, setFailure] = useState<string | null>(null);
    const { register, isLoading, isSupported } = usePasskeyRegister({
        routes: PASSKEY_ROUTES.register,
        onSuccess: () => router.reload(),
        onError: (error) => setFailure(t(passkeyErrorKey(error))),
    });

    if (!isSupported) {
        return <p className="text-sm text-muted-foreground">{t('This browser does not support passkeys.')}</p>;
    }

    return (
        <form
            onSubmit={(e: FormEvent<HTMLFormElement>) => {
                e.preventDefault();
                setFailure(null);
                void register(name.trim() === '' ? t('Passkey') : name.trim());
            }}
            className="space-y-4"
        >
            <Field label={t('Name')} htmlFor="passkey_name" help={t('A label for the list, like "Phone" or "Laptop".')} error={failure ?? undefined}>
                <Input id="passkey_name" type="text" autoComplete="off" maxLength={255} value={name} onChange={(e) => setName(e.target.value)} />
            </Field>
            <FormActions>
                <Button type="submit" loading={isLoading}>
                    <KeyRound className="size-4" aria-hidden />
                    {t('Create passkey')}
                </Button>
            </FormActions>
        </form>
    );
}

function PasskeyCard({ passkey, last }: { passkey: PasskeyRow; last: boolean }) {
    const t = useT();
    const { absolute } = useDateFormat();
    const form = useForm({ current_password: '' });
    const [confirming, setConfirming] = useState(false);

    return (
        <li className="space-y-3 py-4 first:pt-0 last:pb-0">
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                <div className="space-y-0.5">
                    <h3 className="text-base text-foreground">{passkey.name}</h3>
                    <p className="text-sm text-muted-foreground">
                        {passkey.authenticator ?? t('Unknown authenticator')}
                        {' · '}
                        {passkey.synced ? t('Synced') : t('This device only')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {passkey.createdAt ? t('Added :date', { date: absolute(passkey.createdAt) }) : null}
                        {' · '}
                        {passkey.lastUsedAt ? t('Last used :date', { date: absolute(passkey.lastUsedAt) }) : t('Never used')}
                    </p>
                </div>
                {!confirming && (
                    <Button type="button" variant="outline" size="sm" onClick={() => setConfirming(true)}>
                        {t('Remove')}
                    </Button>
                )}
            </div>
            {confirming && (
                <form
                    onSubmit={(e: FormEvent<HTMLFormElement>) => {
                        e.preventDefault();
                        form.delete(`/member/config/passkeys/${passkey.id}`);
                    }}
                    className="space-y-3 rounded-md border border-border p-3"
                >
                    <p className="text-sm text-foreground">
                        {last
                            ? t('Remove this passkey? You will sign in with your password from now on.')
                            : t('Remove this passkey? It will no longer sign you in; your other passkeys and your password still will.')}
                    </p>
                    <Field label={t('Current password')} htmlFor={`delete_password_${passkey.id}`} error={form.errors.current_password}>
                        <Input
                            id={`delete_password_${passkey.id}`}
                            type="password"
                            autoComplete="current-password"
                            value={form.data.current_password}
                            onChange={(e) => form.setData('current_password', e.target.value)}
                        />
                    </Field>
                    <FormActions>
                        <Button type="submit" variant="destructive" size="sm" loading={form.processing}>
                            {t('Remove')}
                        </Button>
                        <Button type="button" variant="outline" size="sm" disabled={form.processing} onClick={() => setConfirming(false)}>
                            {t('Cancel')}
                        </Button>
                    </FormActions>
                </form>
            )}
        </li>
    );
}

export default function ConfigPasskeys({ passkeys, requiresPassword, requiresSecondFactor, deviceBoundOnly }: Props) {
    const t = useT();

    return (
        <SettingsSubpage title={t('Passkeys')}>
            <div className="space-y-6">
                <Explanation />

                <section className="space-y-3">
                    <Heading as="h2" variant="section">
                        {t('Your passkeys')}
                    </Heading>
                    {passkeys.length === 0 ? (
                        <p className="text-sm text-foreground">{t('No passkeys yet.')}</p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {passkeys.map((passkey) => (
                                <PasskeyCard key={passkey.id} passkey={passkey} last={passkeys.length === 1} />
                            ))}
                        </ul>
                    )}
                </section>

                {deviceBoundOnly && (
                    <p className="text-sm text-muted-foreground">
                        {t('Your passkeys live on one device each. Adding one from another device keeps you signed in if it is lost.')}
                    </p>
                )}

                <div className="border-t border-border pt-5">
                    <FormSection title={t('Add a passkey')} headingLevel="h2">
                        {requiresPassword ? <Reauth requiresSecondFactor={requiresSecondFactor} /> : <Register />}
                    </FormSection>
                </div>
            </div>
        </SettingsSubpage>
    );
}
