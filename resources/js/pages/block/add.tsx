import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { useT } from "@/lib/i18n";
import type { PageProps } from "@/types";
import type { BlockMember } from "./types";

interface AddProps extends PageProps {
    target: BlockMember;
}

export default function BlockAdd() {
    const t = useT();
    const { target, flash } = usePage<AddProps>().props;
    const [submitting, setSubmitting] = useState(false);

    function submit() {
        setSubmitting(true);
        router.post(
            "/m/block/add",
            { target_id: target.id },
            { onFinish: () => setSubmitting(false) },
        );
    }

    const title = t("block.block");

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-md space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                {flash.error && <p role="alert">{flash.error}</p>}

                <p>{t("block.confirm_block", { name: target.name })}</p>

                <div className="space-x-2">
                    <button
                        type="button"
                        onClick={submit}
                        disabled={submitting}
                    >
                        {title}
                    </button>
                    <Link href="/m/block/list">{t("Cancel")}</Link>
                </div>
            </main>
        </>
    );
}
