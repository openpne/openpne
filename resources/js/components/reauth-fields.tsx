import { useState } from 'react';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { OtpInput } from '@/components/ui/otp-input';
import { useT } from '@/lib/i18n';

export interface ReauthData {
    current_password: string;
    code: string;
    recovery_code: string;
}

export const REAUTH_DATA: ReauthData = { current_password: '', code: '', recovery_code: '' };

interface Props {
    /** Several of these can share a page, so every field id is prefixed. */
    idPrefix: string;
    data: ReauthData;
    errors: Partial<Record<keyof ReauthData, string>>;
    onChange: (field: keyof ReauthData, value: string) => void;
    /** Clears both second-factor fields and their errors when the member switches between them. */
    onResetSecondFactor: () => void;
    requiresSecondFactor: boolean;
    allowRecoveryCode?: boolean;
    passwordHelp?: string;
    codeHelp?: string;
    autoFocus?: boolean;
}

export function ReauthFields({
    idPrefix,
    data,
    errors,
    onChange,
    onResetSecondFactor,
    requiresSecondFactor,
    allowRecoveryCode = true,
    passwordHelp,
    codeHelp,
    autoFocus = false,
}: Props) {
    const t = useT();
    // The server prefers a filled recovery code, so only the shown field is ever populated.
    const [useRecovery, setUseRecovery] = useState(false);

    return (
        <>
            <Field label={t('Current password')} htmlFor={`${idPrefix}_password`} help={passwordHelp} error={errors.current_password}>
                <Input
                    id={`${idPrefix}_password`}
                    type="password"
                    autoComplete="current-password"
                    autoFocus={autoFocus}
                    value={data.current_password}
                    onChange={(e) => onChange('current_password', e.target.value)}
                />
            </Field>
            {requiresSecondFactor &&
                (useRecovery ? (
                    <Field
                        label={t('Recovery code')}
                        htmlFor={`${idPrefix}_recovery_code`}
                        help={t('Each recovery code can be used once, if you no longer have your authenticator.')}
                        error={errors.recovery_code}
                    >
                        <Input
                            id={`${idPrefix}_recovery_code`}
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
                    </Field>
                ))}
            {requiresSecondFactor && allowRecoveryCode && (
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
