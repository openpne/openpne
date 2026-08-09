import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Card, CardBody } from '@/components/card';
import { Heading } from '@/components/ui/heading';

/**
 * Detail page for one consequential setting (email/password/withdrawal): the page title as h1 and
 * one card holding the form. The chrome registry's CONFIG_CONTEXT crumb (Settings) carries the way
 * back, so this owns only the title.
 */
export function SettingsSubpage({ title, danger = false, children }: { title: string; danger?: boolean; children: ReactNode }) {
    return (
        <>
            <Head title={title} />
            <Heading variant="page" className={danger ? 'text-destructive' : undefined}>
                {title}
            </Heading>
            <Card className={danger ? 'border-destructive/40' : undefined}>
                <CardBody>{children}</CardBody>
            </Card>
        </>
    );
}
