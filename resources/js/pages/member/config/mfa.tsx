import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { Field, FormActions, FormSection } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { Input } from '@/components/ui/input';
import { OtpInput } from '@/components/ui/otp-input';
import { useT } from '@/lib/i18n';

type Props =
    | { state: 'disabled' }
    | { state: 'pending'; qrCode: string; secret: string; requiresPassword: boolean }
    | { state: 'enabled'; recoveryCodesCount: number; recoveryCodes?: string[] };

/**
 * The one-time recovery-code list, shown right after confirm/regenerate minted it. Dashed frame +
 * an explicit "only this once" lead so it reads as a moment to act on, not page furniture that
 * would still be here on the next visit.
 */
function RecoveryCodes({ codes }: { codes: string[] }) {
    const t = useT();

    return (
        <section className="space-y-2 rounded-md border-2 border-dashed border-foreground/30 p-4">
            <Heading as="h2" variant="section">{t('Recovery codes')}</Heading>
            <p className="text-sm font-semibold text-foreground">{t('These codes are shown only this once.')}</p>
            <p className="text-sm text-muted-foreground">
                {t('Save them somewhere safe now — each can be used once to sign in if you lose your authenticator.')}
            </p>
            <ul className="grid grid-cols-1 gap-1 pt-1 sm:grid-cols-2">
                {codes.map((code) => (
                    <li key={code}>
                        <code className="text-sm">{code}</code>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Disabled() {
    const t = useT();
    const form = useForm({ current_password: '' });

    return (
        <form
            onSubmit={(e: FormEvent<HTMLFormElement>) => {
                e.preventDefault();
                form.post('/member/config/mfa/enable');
            }}
        >
            <div className="space-y-4">
                <p className="text-sm text-foreground">{t('To continue, first confirm it is you.')}</p>
                <Field label={t('Current password')} htmlFor="current_password" error={form.errors.current_password}>
                    <Input
                        id="current_password"
                        type="password"
                        autoComplete="current-password"
                        autoFocus
                        value={form.data.current_password}
                        onChange={(e) => form.setData('current_password', e.target.value)}
                    />
                </Field>
                <FormActions>
                    <Button type="submit" loading={form.processing}>
                        {t('Set up two-factor authentication')}
                    </Button>
                </FormActions>
            </div>
        </form>
    );
}

function Pending({ qrCode, secret, requiresPassword }: { qrCode: string; secret: string; requiresPassword: boolean }) {
    const t = useT();
    const form = useForm({ code: '', current_password: '' });
    // Cancelling a pending set-up needs no fields (the pending secret gates nothing).
    const cancel = useForm({});

    return (
        <div className="space-y-4">
            <p className="text-xs text-muted-foreground">
                {t('You need an authenticator app that generates a one-time code at login. Search your device\'s app store for "authenticator" and install one.')}
            </p>
            <p className="text-sm text-foreground">{t('Scan the following QR code with your authenticator app:')}</p>
            {/* The padding is the QR quiet zone: it must stay the QR's own background color in both
                themes (functional, not thematic — an inline style like BrandMark's configured color). */}
            <img
                src={qrCode}
                alt={t('QR code for your authenticator app')}
                width={192}
                height={192}
                className="rounded-md border border-border p-2"
                style={{ backgroundColor: '#fff' }}
            />
            <p className="text-sm text-muted-foreground">
                {t('Or enter the following code manually:')} <code className="select-all">{secret}</code>
            </p>
            <form
                onSubmit={(e: FormEvent<HTMLFormElement>) => {
                    e.preventDefault();
                    // Send the password only when the form actually shows it (the server treats
                    // an empty string as absent either way).
                    form.transform((data) => (data.current_password === '' ? { code: data.code } : data));
                    form.post('/member/config/mfa/confirm');
                }}
            >
                <div className="space-y-4">
                    <Field label={t('Authentication code')} htmlFor="code" error={form.errors.code}>
                        <OtpInput label={t('Authentication code')} value={form.data.code} onChange={(code) => form.setData('code', code)} />
                    </Field>
                    {requiresPassword && (
                        <Field
                            label={t('Current password')}
                            htmlFor="current_password"
                            help={t('Some time has passed since you started, so please confirm it is you: enter your current password as well.')}
                            error={form.errors.current_password}
                        >
                            <Input
                                id="current_password"
                                type="password"
                                autoComplete="current-password"
                                value={form.data.current_password}
                                onChange={(e) => form.setData('current_password', e.target.value)}
                            />
                        </Field>
                    )}
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Confirm')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing || cancel.processing}
                            onClick={() => cancel.post('/member/config/mfa/disable')}
                        >
                            {t('Cancel set-up')}
                        </Button>
                    </FormActions>
                </div>
            </form>
        </div>
    );
}

function Enabled({ recoveryCodesCount, recoveryCodes }: { recoveryCodesCount: number; recoveryCodes?: string[] }) {
    const t = useT();
    const regenerate = useForm({ current_password: '', code: '' });
    const disable = useForm({ current_password: '', code: '', recovery_code: '' });
    // The disable proof is either a TOTP code or a recovery code; the server prefers a filled
    // recovery_code, so only the active field is populated.
    const [useRecovery, setUseRecovery] = useState(false);

    function toggleRecovery() {
        // Clear the now-inactive proof AND its stale error: reset() alone leaves a prior invalid
        // attempt's error to reappear on toggling back. The password is kept.
        disable.resetAndClearErrors('code', 'recovery_code');
        setUseRecovery((v) => !v);
    }

    return (
        <div className="space-y-6">
            <p className="text-sm text-foreground">{t('Two-factor authentication is enabled.')}</p>

            {recoveryCodes ? (
                <RecoveryCodes codes={recoveryCodes} />
            ) : (
                <p className="text-sm text-muted-foreground">
                    {t('Unused recovery codes')}: {recoveryCodesCount}
                </p>
            )}

            {/* Each management form leads with a heading + what it does; the password and code
                fields are the means, not the message. */}
            <div className="border-t border-border pt-5">
                <FormSection
                    title={t('Regenerate recovery codes')}
                    headingLevel="h2"
                    description={t('Regenerating replaces every unused recovery code with a fresh set.')}
                >
                    <form
                        onSubmit={(e: FormEvent<HTMLFormElement>) => {
                            e.preventDefault();
                            regenerate.post('/member/config/mfa/recovery-codes');
                        }}
                        className="space-y-4"
                    >
                        <Field label={t('Current password')} htmlFor="regenerate_password" error={regenerate.errors.current_password}>
                            <Input
                                id="regenerate_password"
                                type="password"
                                autoComplete="current-password"
                                value={regenerate.data.current_password}
                                onChange={(e) => regenerate.setData('current_password', e.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('Authentication code')}
                            htmlFor="regenerate_code"
                            help={t('Enter the six-digit code shown in your authenticator app.')}
                            error={regenerate.errors.code}
                        >
                            <OtpInput
                                label={t('Authentication code')}
                                value={regenerate.data.code}
                                onChange={(code) => regenerate.setData('code', code)}
                            />
                        </Field>
                        <FormActions>
                            <Button type="submit" variant="outline" loading={regenerate.processing}>
                                {t('Regenerate recovery codes')}
                            </Button>
                        </FormActions>
                    </form>
                </FormSection>
            </div>

            <div className="border-t border-border pt-5">
                <FormSection
                    title={t('Disable two-factor authentication')}
                    headingLevel="h2"
                    description={t('Your password alone will sign you in again.')}
                >
                    <form
                        onSubmit={(e: FormEvent<HTMLFormElement>) => {
                            e.preventDefault();
                            disable.post('/member/config/mfa/disable');
                        }}
                        className="space-y-4"
                    >
                        <Field label={t('Current password')} htmlFor="disable_password" error={disable.errors.current_password}>
                            <Input
                                id="disable_password"
                                type="password"
                                autoComplete="current-password"
                                value={disable.data.current_password}
                                onChange={(e) => disable.setData('current_password', e.target.value)}
                            />
                        </Field>
                        {useRecovery ? (
                            <Field
                                label={t('Recovery code')}
                                htmlFor="disable_recovery_code"
                                help={t('Each recovery code can be used once, if you no longer have your authenticator.')}
                                error={disable.errors.recovery_code}
                            >
                                <Input
                                    id="disable_recovery_code"
                                    type="text"
                                    autoComplete="off"
                                    value={disable.data.recovery_code}
                                    onChange={(e) => disable.setData('recovery_code', e.target.value)}
                                />
                            </Field>
                        ) : (
                            <Field
                                label={t('Authentication code')}
                                htmlFor="disable_code"
                                help={t('Enter the six-digit code shown in your authenticator app.')}
                                error={disable.errors.code}
                            >
                                <OtpInput
                                    label={t('Authentication code')}
                                    value={disable.data.code}
                                    onChange={(code) => disable.setData('code', code)}
                                />
                            </Field>
                        )}
                        <p className="text-sm">
                            <button type="button" onClick={toggleRecovery} className="text-link hover:underline">
                                {useRecovery ? t('Use an authentication code instead') : t('Use a recovery code instead')}
                            </button>
                        </p>
                        <FormActions>
                            <Button type="submit" variant="destructive" loading={disable.processing}>
                                {t('Disable two-factor authentication')}
                            </Button>
                        </FormActions>
                    </form>
                </FormSection>
            </div>
        </div>
    );
}

export default function ConfigMfa(props: Props) {
    const t = useT();

    return (
        <SettingsSubpage title={t('Two-factor authentication')}>
            {props.state === 'disabled' && <Disabled />}
            {props.state === 'pending' && <Pending qrCode={props.qrCode} secret={props.secret} requiresPassword={props.requiresPassword} />}
            {props.state === 'enabled' && <Enabled recoveryCodesCount={props.recoveryCodesCount} recoveryCodes={props.recoveryCodes} />}
        </SettingsSubpage>
    );
}
