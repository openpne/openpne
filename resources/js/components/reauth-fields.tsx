import { Passkeys } from '@laravel/passkeys';
import { usePage } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { OtpInput } from '@/components/ui/otp-input';
import { useT } from '@/lib/i18n';
import { PASSKEY_ROUTES, passkeyErrorKey } from '@/lib/passkeys';
import type { PageProps } from '@/types';

export interface ReauthData {
    current_password: string;
    code: string;
    recovery_code: string;
}

export const REAUTH_DATA: ReauthData = { current_password: '', code: '', recovery_code: '' };

/** The server's `ReauthPurpose` values: a passkey proof opens only the step-up it was made for. */
export type ReauthPurpose =
    | 'mfa-setup'
    | 'mfa-disable'
    | 'mfa-recovery-codes'
    | 'passkey-add'
    | 'passkey-remove'
    | 'password-change'
    | 'email-change'
    | 'withdrawal'
    | 'ai-account-delete'
    | 'ai-token'
    | 'oauth-consent'
    | 'oauth-connection-revoke';

interface Props {
    /** Several of these can share a page, so every field id is prefixed. */
    idPrefix: string;
    purpose: ReauthPurpose;
    data: ReauthData;
    errors: Partial<Record<keyof ReauthData, string>>;
    onChange: (field: keyof ReauthData, value: string) => void;
    /** Clears both second-factor fields and their errors when the member switches between them. */
    onResetSecondFactor: () => void;
    /** Defaults to whether the signed-in member holds a confirmed factor. */
    requiresSecondFactor?: boolean;
    allowRecoveryCode?: boolean;
    /** For a form the browser submits itself: the fields carry their names. */
    native?: boolean;
    passwordHelp?: string;
    codeHelp?: string;
    autoFocus?: boolean;
}

export function ReauthFields({
    idPrefix,
    purpose,
    data,
    errors,
    onChange,
    onResetSecondFactor,
    requiresSecondFactor,
    allowRecoveryCode = true,
    native = false,
    passwordHelp,
    codeHelp,
    autoFocus = false,
}: Props) {
    const t = useT();
    const viewer = usePage<PageProps>().props.auth.user;
    const holdsFactor = viewer?.hasTwoFactor ?? false;
    const offersPasskey = (viewer?.hasPasskey ?? false) && Passkeys.isSupported();
    const passkeyButton = useRef<HTMLButtonElement>(null);
    const [proving, setProving] = useState(false);
    const [passkeyFailure, setPasskeyFailure] = useState<string | null>(null);

    const proveWithPasskey = async () => {
        setPasskeyFailure(null);
        setProving(true);
        try {
            await Passkeys.verify({
                routes: { options: `${PASSKEY_ROUTES.reauth.options}?purpose=${purpose}`, submit: PASSKEY_ROUTES.reauth.submit },
            });
        } catch (error) {
            setPasskeyFailure(t(passkeyErrorKey(error)));
            setProving(false);

            return;
        }
        // The server verifies a password whenever one is sent, so it is emptied before the form goes.
        flushSync(() => onChange('current_password', ''));
        setProving(false);
        passkeyButton.current?.closest('form')?.requestSubmit();
    };
    const asksSecondFactor = requiresSecondFactor ?? holdsFactor;
    // The server prefers a filled recovery code, so only the shown field is ever populated.
    const [useRecovery, setUseRecovery] = useState(false);

    return (
        <>
            <Field label={t('Current password')} htmlFor={`${idPrefix}_password`} help={passwordHelp} error={errors.current_password}>
                <Input
                    id={`${idPrefix}_password`}
                    name={native ? 'current_password' : undefined}
                    type="password"
                    autoComplete="current-password"
                    autoFocus={autoFocus}
                    value={data.current_password}
                    onChange={(e) => onChange('current_password', e.target.value)}
                />
            </Field>
            {asksSecondFactor &&
                (useRecovery ? (
                    <Field
                        label={t('Recovery code')}
                        htmlFor={`${idPrefix}_recovery_code`}
                        help={t('Each recovery code can be used once, if you no longer have your authenticator.')}
                        error={errors.recovery_code}
                    >
                        <Input
                            id={`${idPrefix}_recovery_code`}
                            name={native ? 'recovery_code' : undefined}
                            type="text"
                            autoComplete="off"
                            value={data.recovery_code}
                            onChange={(e) => onChange('recovery_code', e.target.value)}
                        />
                    </Field>
                ) : (
                    <Field
                        label={t('Authentication code')}
                        htmlFor={`${idPrefix}_code`}
                        help={codeHelp ?? t('Enter the six-digit code shown in your authenticator app.')}
                        error={errors.code}
                    >
                        <OtpInput label={t('Authentication code')} value={data.code} onChange={(code) => onChange('code', code)} />
                        {native && <input type="hidden" name="code" value={data.code} />}
                    </Field>
                ))}
            {offersPasskey && (
                <div className="space-y-2">
                    <Button ref={passkeyButton} type="button" variant="outline" loading={proving} onClick={() => void proveWithPasskey()}>
                        <KeyRound className="size-4" aria-hidden />
                        {t('Confirm with a passkey instead')}
                    </Button>
                    {passkeyFailure && (
                        <p className="text-sm text-destructive" role="alert">
                            {passkeyFailure}
                        </p>
                    )}
                </div>
            )}
            {asksSecondFactor && allowRecoveryCode && (
                <p className="text-sm">
                    <button
                        type="button"
                        onClick={() => {
                            onResetSecondFactor();
                            setUseRecovery((v) => !v);
                        }}
                        className="text-link hover:underline"
                    >
                        {useRecovery ? t('Use an authentication code instead') : t('Use a recovery code instead')}
                    </button>
                </p>
            )}
        </>
    );
}
