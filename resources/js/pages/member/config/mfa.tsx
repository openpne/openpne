import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { Field, FormActions } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

type Props =
    | { state: 'disabled' }
    | { state: 'pending'; qrCode: string; secret: string }
    | { state: 'enabled'; recoveryCodesCount: number; recoveryCodes?: string[] };

/** The one-time recovery-code list, shown right after confirm/regenerate minted it. */
function RecoveryCodes({ codes }: { codes: string[] }) {
    const t = useT();

    return (
        <div className="space-y-2 rounded-md border border-border bg-muted/40 p-4">
            <p className="text-sm font-semibold text-foreground">
                {t('Store these codes somewhere safe. Each can be used once if you lose your authenticator.')}
            </p>
            <ul className="grid grid-cols-1 gap-1 sm:grid-cols-2">
                {codes.map((code) => (
                    <li key={code}>
                        <code className="text-sm">{code}</code>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Disabled() {
    const t = useT();
    const form = useForm({ current_password: '' });

    return (
        <form
            onSubmit={(e: FormEvent<HTMLFormElement>) => {
                e.preventDefault();
                form.post('/m/member/config/mfa/enable');
            }}
        >
            <div className="space-y-4">
                <div className="space-y-2 text-sm text-muted-foreground">
                    <p>
                        {t(
                            'When two-factor authentication is on, signing in asks for a six-digit one-time code in addition to your password — so a leaked password alone cannot open your account.',
                        )}
                    </p>
                    <p>
                        {t(
                            'You need an authenticator app that generates the code. Search your device\'s app store for "authenticator" and install one before you start.',
                        )}
                    </p>
                </div>
                <Field label={t('Current password')} htmlFor="current_password" error={form.errors.current_password}>
                    <Input
                        id="current_password"
                        type="password"
                        autoComplete="current-password"
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

function Pending({ qrCode, secret }: { qrCode: string; secret: string }) {
    const t = useT();
    // One password entry serves both outcomes: Confirm submits it with the code; Cancel posts the
    // same re-auth to the disable endpoint (which clears the pending secret).
    const form = useForm({ current_password: '', code: '' });

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                {t('Scan this QR code with your authenticator app. The app will show a six-digit code — enter it below to finish setting up.')}
            </p>
            {/* The padding is the QR quiet zone: it must stay the QR's own background color in both
                themes (functional, not thematic — an inline style like the identity-mark colors). */}
            <img
                src={qrCode}
                alt={t('QR code for your authenticator app')}
                width={192}
                height={192}
                className="rounded-md border border-border p-2"
                style={{ backgroundColor: '#fff' }}
            />
            <p className="text-sm text-muted-foreground">
                {t('Setup key')}: <code className="select-all">{secret}</code>
            </p>
            <form
                onSubmit={(e: FormEvent<HTMLFormElement>) => {
                    e.preventDefault();
                    form.post('/m/member/config/mfa/confirm');
                }}
            >
                <div className="space-y-4">
                    <Field label={t('Current password')} htmlFor="current_password" error={form.errors.current_password}>
                        <Input
                            id="current_password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.current_password}
                            onChange={(e) => form.setData('current_password', e.target.value)}
                        />
                    </Field>
                    <Field label={t('Authentication code')} htmlFor="code" error={form.errors.code}>
                        <Input
                            id="code"
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                        />
                    </Field>
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Confirm')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => form.post('/m/member/config/mfa/disable')}
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
    const regenerate = useForm({ current_password: '' });
    const disable = useForm({ current_password: '' });

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

            <form
                onSubmit={(e: FormEvent<HTMLFormElement>) => {
                    e.preventDefault();
                    regenerate.post('/m/member/config/mfa/recovery-codes');
                }}
                className="space-y-4 border-t border-border pt-4"
            >
                <Field
                    label={t('Current password')}
                    htmlFor="regenerate_password"
                    help={t('Regenerating replaces every unused recovery code with a fresh set.')}
                    error={regenerate.errors.current_password}
                >
                    <Input
                        id="regenerate_password"
                        type="password"
                        autoComplete="current-password"
                        value={regenerate.data.current_password}
                        onChange={(e) => regenerate.setData('current_password', e.target.value)}
                    />
                </Field>
                <FormActions>
                    <Button type="submit" variant="outline" loading={regenerate.processing}>
                        {t('Regenerate recovery codes')}
                    </Button>
                </FormActions>
            </form>

            <form
                onSubmit={(e: FormEvent<HTMLFormElement>) => {
                    e.preventDefault();
                    disable.post('/m/member/config/mfa/disable');
                }}
                className="space-y-4 border-t border-border pt-4"
            >
                <Field
                    label={t('Current password')}
                    htmlFor="disable_password"
                    help={t('Your password alone will sign you in again.')}
                    error={disable.errors.current_password}
                >
                    <Input
                        id="disable_password"
                        type="password"
                        autoComplete="current-password"
                        value={disable.data.current_password}
                        onChange={(e) => disable.setData('current_password', e.target.value)}
                    />
                </Field>
                <FormActions>
                    <Button type="submit" variant="destructive" loading={disable.processing}>
                        {t('Disable two-factor authentication')}
                    </Button>
                </FormActions>
            </form>
        </div>
    );
}

export default function ConfigMfa(props: Props) {
    const t = useT();

    return (
        <SettingsSubpage title={t('Two-factor authentication')}>
            {props.state === 'disabled' && <Disabled />}
            {props.state === 'pending' && <Pending qrCode={props.qrCode} secret={props.secret} />}
            {props.state === 'enabled' && <Enabled recoveryCodesCount={props.recoveryCodesCount} recoveryCodes={props.recoveryCodes} />}
        </SettingsSubpage>
    );
}
