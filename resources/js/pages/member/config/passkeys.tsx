import { router, useForm } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { KeyRound, TriangleAlert } from 'lucide-react';
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
    /** The provider the passkey is kept in; null when the authenticator did not say. */
    name: string | null;
    synced: boolean;
    deviceBound: boolean;
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
        <div className="space-y-3">
            <p className="text-sm text-foreground">{t('A passkey is a simple, safe way to sign in without your password.')}</p>
            <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                <li>{t('Nothing to remember, nothing to type.')}</li>
                <li>{t('You sign in the way you unlock your device: fingerprint, face or PIN.')}</li>
                <li>{t('The check happens on your device, so your fingerprint and face never reach this site.')}</li>
            </ul>
            <p className="text-sm text-muted-foreground">{t('Registering a passkey leaves your password working as before.')}</p>
            <p className="flex items-start gap-1.5 text-sm text-foreground">
                <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                {t('Do not register one on a device you share: anyone who can unlock it can sign in as you.')}
            </p>
        </div>
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
    const [failure, setFailure] = useState<string | null>(null);
    const lapsed = t('Some time has passed since you confirmed your password. Please confirm it again.');
    const { register, isLoading, isSupported } = usePasskeyRegister({
        routes: PASSKEY_ROUTES.register,
        onSuccess: () => router.reload(),
        onError: (error) => {
            const message = t(passkeyErrorKey(error));
            // A lapsed window is the server's 403: re-render so the password form comes back.
            if (message === lapsed) {
                router.reload();
                return;
            }
            setFailure(message);
        },
    });

    if (!isSupported) {
        return <p className="text-sm text-muted-foreground">{t('This browser does not support passkeys.')}</p>;
    }

    return (
        <div className="space-y-3">
            <FormActions>
                <Button
                    type="button"
                    loading={isLoading}
                    onClick={() => {
                        setFailure(null);
                        // The server names it after where it is kept, so nothing is asked for here.
                        void register('');
                    }}
                >
                    <KeyRound className="size-4" aria-hidden />
                    {t('Create passkey')}
                </Button>
            </FormActions>
            {failure && (
                <p className="text-sm text-destructive" role="alert">
                    {failure}
                </p>
            )}
        </div>
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
                    <h3 className="text-base text-foreground">{passkey.name ?? t('Passkey')}</h3>
                    {(passkey.synced || passkey.deviceBound) && (
                        <p className="text-sm text-muted-foreground">
                            {passkey.synced ? t('Works on your other devices too') : t('Works on this device only')}
                        </p>
                    )}
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
