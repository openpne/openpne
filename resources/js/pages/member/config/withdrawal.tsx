import { useForm } from '@inertiajs/react';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { CheckboxField, Field, FormActions } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

export default function ConfigWithdrawal() {
    const t = useT();
    const form = useForm({ password: '', confirm: false });

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
                    <Field label={t('Current password')} htmlFor="withdraw_password" error={form.errors.password}>
                        <Input
                            id="withdraw_password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                    </Field>
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
