import { useForm } from '@inertiajs/react';
import { REAUTH_DATA, ReauthFields } from '@/components/reauth-fields';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { Field, FormActions } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

export default function ConfigPassword() {
    const t = useT();
    const form = useForm({ ...REAUTH_DATA, password: '', password_confirmation: '' });

    return (
        <SettingsSubpage title={t('Change password')}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/member/config/password');
                }}
            >
                <div className="space-y-4">
                    <ReauthFields
                        idPrefix="password_change"
                        purpose="password-change"
                        data={form.data}
                        errors={form.errors}
                        onChange={(field, value) => form.setData(field, value)}
                        onResetSecondFactor={() => form.resetAndClearErrors('code', 'recovery_code')}
                    />
                    <Field label={t('New password')} htmlFor="password" error={form.errors.password}>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                    </Field>
                    <Field label={t('New password (confirm)')} htmlFor="password_confirmation">
                        <Input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password_confirmation}
                            onChange={(e) => form.setData('password_confirmation', e.target.value)}
                        />
                    </Field>
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Save')}
                        </Button>
                    </FormActions>
                </div>
            </form>
        </SettingsSubpage>
    );
}
