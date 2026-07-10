import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Panel } from "@/components/ui/surface";
import { useT } from "@/lib/i18n";
import type { PageProps } from "@/types";
import type { BlockMember } from "./types";

interface AddProps extends PageProps {
    target: BlockMember;
}

export default function BlockAdd() {
    const t = useT();
    const { target } = usePage<AddProps>().props;
    const [submitting, setSubmitting] = useState(false);

    function submit() {
        setSubmitting(true);
        router.post(
            "/block/add",
            { target_id: target.id },
            { onFinish: () => setSubmitting(false) },
        );
    }

    const title = t("Block");

    return (
        <>
            <Head title={title} />
            <h1 className="break-words text-xl font-semibold text-foreground">{title}</h1>

            <Panel bodyClassName="space-y-4">
                <p className="text-foreground">{t("Block :name?", { name: target.name })}</p>

                <div className="flex items-center gap-3">
                    <Button type="button" variant="destructive" onClick={submit} loading={submitting}>
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
