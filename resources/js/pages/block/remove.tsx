import { Heading } from '@/components/ui/heading';
import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Panel } from "@/components/ui/surface";
import { useT } from "@/lib/i18n";
import type { PageProps } from "@/types";
import type { BlockMember } from "./types";

interface RemoveProps extends PageProps {
    target: BlockMember;
}

export default function BlockRemove() {
    const t = useT();
    const { target } = usePage<RemoveProps>().props;
    const [submitting, setSubmitting] = useState(false);

    function submit() {
        setSubmitting(true);
        router.post(
            `/block/remove/${target.id}`,
            {},
            { onFinish: () => setSubmitting(false) },
        );
    }

    const title = t("Unblock");

    return (
        <>
            <Head title={title} />
            <Heading variant="page">{title}</Heading>

            <Panel bodyClassName="space-y-4">
                <p className="text-foreground">{t("Unblock :name?", { name: target.name })}</p>

                <div className="flex items-center gap-3">
                    <Button type="button" onClick={submit} loading={submitting}>
                        {title}
                    </Button>
                    <Link href="/block/list" className="text-sm text-link hover:underline">
                        {t("Cancel")}
                    </Link>
                </div>
            </Panel>
        </>
    );
}
