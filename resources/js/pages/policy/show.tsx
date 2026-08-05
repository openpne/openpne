import { Head, usePage } from '@inertiajs/react';
import { RichBody } from '@/components/rich-body';
import { useT } from '@/lib/i18n';
import { POLICY_TITLES, type PolicyKind } from '@/lib/member-chrome';
import type { PageProps } from '@/types';

interface PolicyProps extends PageProps {
    kind: PolicyKind;
    body: string | null;
    bodyHtml: string | null;
}

/** Terms of service / privacy policy. The h1 is the frame's (contextual chrome), so the page is the body. */
export default function PolicyShow() {
    const t = useT();
    const { kind, body, bodyHtml } = usePage<PolicyProps>().props;

    return (
        <>
            <Head title={t(POLICY_TITLES[kind].key)} />

            {body === null ? <p className="text-muted-foreground">{t('This page is not written yet.')}</p> : <RichBody body={body} bodyHtml={bodyHtml} />}
        </>
    );
}
