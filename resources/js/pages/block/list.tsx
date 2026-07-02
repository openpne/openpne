import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { FlashMessage } from "@/components/flash-message";
import { Pagination } from "@/components/pagination";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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

    function add(e: FormEvent) {
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
                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

                <section className="space-y-2">
                    <h1 className="text-xl font-semibold text-foreground">
                        {t("Block a member")}
                    </h1>
                    <form onSubmit={add} className="flex items-center gap-2">
                        <label htmlFor="block_member_id" className="text-sm font-medium text-foreground">
                            {t("Member ID")}
                        </label>
                        <Input
                            id="block_member_id"
                            type="number"
                            min="1"
                            required
                            className="w-32"
                            value={memberId}
                            onChange={(e) => setMemberId(e.target.value)}
                        />
                        <Button type="submit">{t("Block")}</Button>
                    </form>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            "The member ID is the number at the end of the member page URL.",
                        )}
                    </p>
                </section>

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold text-foreground">
                        {t("Blocked members")}
                    </h2>
                    {blocks.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t("No blocked members.")}</p>
                    ) : (
                        <>
                            <ul className="space-y-2">
                                {blocks.data.map((blocked) => (
                                    <li
                                        key={blocked.id}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span className="min-w-0 truncate text-foreground">{blocked.name}</span>
                                        <Link
                                            href={`/m/block/remove/${blocked.id}`}
                                            className="shrink-0 text-sm text-muted-foreground hover:text-foreground hover:underline"
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
