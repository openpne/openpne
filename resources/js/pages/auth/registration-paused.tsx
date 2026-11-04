import { Head, Link } from '@inertiajs/react';
import { RegistrationPaused } from '@/components/registration-paused';
import { AuthLayout } from '@/layouts/auth-layout';
import { useT } from '@/lib/i18n';

export default function RegistrationPausedPage() {
    const t = useT();
    const title = t('Register');

    return (
        <AuthLayout title={title}>
            <Head title={title} />

            <RegistrationPaused />

            <p className="text-center text-sm text-muted-foreground">
                <Link href="/login" className="text-link hover:underline">
                    {t('Back to login')}
                </Link>
            </p>
        </AuthLayout>
    );
}
