import { Head, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardBody } from '@/components/card';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Detail page for one consequential setting (email/password/withdrawal): a back link to the
 * settings hub, the page title as h1, and one card holding the form.
 */
export function SettingsSubpage({ title, danger = false, children }: { title: string; danger?: boolean; children: ReactNode }) {
    const t = useT();

    return (
        <>
            <Head title={title} />
            <div className="space-y-1">
                <Link
                    href="/m/member/config"
                    className="inline-flex items-center gap-1 rounded text-sm text-muted-foreground outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <ChevronLeft className="size-4" aria-hidden="true" />
                    {t('Back to settings')}
                </Link>
                <h1 className={cn('text-xl font-semibold', danger ? 'text-destructive' : 'text-foreground')}>{title}</h1>
            </div>
            <Card className={danger ? 'border-destructive/40' : undefined}>
                <CardBody>{children}</CardBody>
            </Card>
        </>
    );
}
