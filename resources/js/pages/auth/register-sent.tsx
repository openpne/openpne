import { Head, Link } from '@inertiajs/react';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

export default function RegisterSent() {
    const t = useT();
    const title = t('Register');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <p className="text-sm text-foreground">{t('We sent you a registration link.')}</p>
            <p className="text-sm text-muted-foreground">
                {t('Open the link in the mail to begin your registration.')}
            </p>

            <p className="text-center text-sm text-muted-foreground">
                <Link href="/login" className="underline">
                    {t('Back to login')}
                </Link>
            </p>
        </AuthLayout>
    );
}
