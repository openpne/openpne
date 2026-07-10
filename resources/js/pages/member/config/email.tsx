import { useForm } from '@inertiajs/react';
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
    const form = useForm({ new_email: '', password: '' });

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
                    <Field
                        label={t('Current password')}
                        htmlFor="email_password"
                        error={form.errors.password}
                        help={t('A confirmation link will be sent to the new address. The change takes effect once you open it.')}
                    >
                        <Input
                            id="email_password"
                            type="password"
                            autoComplete="current-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                        />
                    </Field>
                    <FormActions>
                        <Button type="submit" loading={form.processing}>
                            {t('Send confirmation')}
                        </Button>
                    </FormActions>
                </div>
            </form>
        </SettingsSubpage>
    );
}
