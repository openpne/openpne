import { useForm } from '@inertiajs/react';
import { REAUTH_DATA, ReauthFields } from '@/components/reauth-fields';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { CheckboxField, FormActions } from '@/components/ui/field';
import { useT } from '@/lib/i18n';

export default function ConfigWithdrawal() {
    const t = useT();
    const form = useForm({ ...REAUTH_DATA, confirm: false });

    return (
        <SettingsSubpage title={t('Account withdrawal')} danger>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/member/config/withdrawal');
                }}
            >
                <div className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {t('Withdrawing permanently deletes your account and cannot be undone.')}
                    </p>
                    <ReauthFields
                        idPrefix="withdrawal"
                        purpose="withdrawal"
                        data={form.data}
                        errors={form.errors}
                        onChange={(field, value) => form.setData(field, value)}
                        onResetSecondFactor={() => form.resetAndClearErrors('code', 'recovery_code')}
                        onProved={() => form.clearErrors('current_password')}
                    />
                    <CheckboxField
                        label={t('Yes, delete my account.')}
                        checked={form.data.confirm}
                        onChange={(e) => form.setData('confirm', e.target.checked)}
                        error={form.errors.confirm}
                    />
                    <FormActions>
                        <Button type="submit" variant="destructive" loading={form.processing}>
                            {t('Withdraw from this site')}
                        </Button>
                    </FormActions>
                </div>
            </form>
        </SettingsSubpage>
    );
}
