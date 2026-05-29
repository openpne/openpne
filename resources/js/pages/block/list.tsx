import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { Pagination } from "@/components/pagination";
import { useT } from "@/lib/i18n";
import type { PageProps } from "@/types";
import type { PaginatedBlocks } from "./types";

interface ListProps extends PageProps {
    blocks: PaginatedBlocks;
}

export default function BlockList() {
    const t = useT();
    const { blocks, flash } = usePage<ListProps>().props;
    const [memberId, setMemberId] = useState("");

    function add(e: React.FormEvent) {
        e.preventDefault();
        if (memberId === "") {
            return;
        }
        router.get("/m/block/add", { id: memberId });
    }

    return (
        <>
            <Head title={t("Blocked members")} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                <section className="space-y-2">
                    <h1 className="text-2xl font-semibold">
                        {t("Block a member")}
                    </h1>
                    <form onSubmit={add} className="flex items-center gap-2">
                        <label htmlFor="block_member_id">
                            {t("Member ID")}
                        </label>
                        <input
                            id="block_member_id"
                            type="number"
                            min="1"
                            required
                            value={memberId}
                            onChange={(e) => setMemberId(e.target.value)}
                        />
                        <button type="submit">{t("Block")}</button>
                    </form>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            "The member ID is the number at the end of the member page URL.",
                        )}
                    </p>
                </section>

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold">
                        {t("Blocked members")}
                    </h2>
                    {blocks.data.length === 0 ? (
                        <p>{t("No blocked members.")}</p>
                    ) : (
                        <>
                            <ul className="space-y-2">
                                {blocks.data.map((blocked) => (
                                    <li
                                        key={blocked.id}
                                        className="flex items-center justify-between"
                                    >
                                        <span>{blocked.name}</span>
                                        <Link
                                            href={`/m/block/remove/${blocked.id}`}
                                            className="text-sm text-muted-foreground hover:underline"
                                        >
                                            {t("Unblock")}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            <Pagination meta={blocks.meta} />
                        </>
                    )}
                </section>
            </main>
        </>
    );
}
