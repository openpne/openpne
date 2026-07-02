import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { FlashMessage } from "@/components/flash-message";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n";
import type { PageProps } from "@/types";
import type { BlockMember } from "./types";

interface RemoveProps extends PageProps {
    target: BlockMember;
}

export default function BlockRemove() {
    const t = useT();
    const { target, flash } = usePage<RemoveProps>().props;
    const [submitting, setSubmitting] = useState(false);

    function submit() {
        setSubmitting(true);
        router.post(
            `/m/block/remove/${target.id}`,
            {},
            { onFinish: () => setSubmitting(false) },
        );
    }

    const title = t("Unblock");

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-md space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <p className="text-foreground">{t("Unblock :name?", { name: target.name })}</p>

                <div className="flex items-center gap-3">
                    <Button type="button" onClick={submit} loading={submitting}>
                        {title}
                    </Button>
                    <Link href="/m/block/list" className="text-sm text-link hover:underline">
                        {t("Cancel")}
                    </Link>
                </div>
            </main>
        </>
    );
}
