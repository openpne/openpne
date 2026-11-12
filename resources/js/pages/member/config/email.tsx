import { useForm } from '@inertiajs/react';
import { REAUTH_DATA, ReauthFields } from '@/components/reauth-fields';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { Field, FormActions } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

interface Props {
    email: string;
}

export default function ConfigEmail({ email }: Props) {
    const t = useT();
    const form = useForm({ ...REAUTH_DATA, new_email: '' });

    return (
        <SettingsSubpage title={t('Change email address')}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/member/config/email');
                }}
            >
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">{`${t('Current email address')}: ${email}`}</p>
                    <Field label={t('New email address')} htmlFor="new_email" error={form.errors.new_email}>
                        <Input
                            id="new_email"
                            type="email"
                            value={form.data.new_email}
                            onChange={(e) => form.setData('new_email', e.target.value)}
                        />
                    </Field>
                    <ReauthFields
                        idPrefix="email_change"
                        data={form.data}
                        errors={form.errors}
                        onChange={(field, value) => form.setData(field, value)}
                        onResetSecondFactor={() => form.resetAndClearErrors('code', 'recovery_code')}
                        passwordHelp={t('A confirmation link will be sent to the new address. The change takes effect once you open it.')}
                    />
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Send a confirmation email')}
                        </Button>
                    </FormActions>
                </div>
            </form>
        </SettingsSubpage>
    );
}
