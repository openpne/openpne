import { Head, Link, router, usePage } from "@inertiajs/react";
import { useState, type FormEvent } from "react";
import { Avatar } from "@/components/avatar";
import { Pagination } from "@/components/pagination";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { List, ListRow, Panel } from "@/components/ui/surface";
import { useT } from "@/lib/i18n";
import type { PageProps } from "@/types";
import type { PaginatedBlocks } from "./types";

interface ListProps extends PageProps {
    blocks: PaginatedBlocks;
}

export default function BlockList() {
    const t = useT();
    const { blocks } = usePage<ListProps>().props;
    const [memberId, setMemberId] = useState("");
    const [adding, setAdding] = useState(false);

    function add(e: FormEvent) {
        e.preventDefault();
        if (memberId === "") {
            return;
        }
        router.get("/m/block/add", { id: memberId }, {
            onStart: () => setAdding(true),
            onFinish: () => setAdding(false),
        });
    }

    return (
        <>
            <Head title={t("Blocked members")} />

            <div className="space-y-2">
                <h1 className="break-words text-xl font-semibold text-foreground">
                    {t("Block a member")}
                </h1>
                <Panel bodyClassName="space-y-3">
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
                        <Button type="submit" loading={adding}>{t("Block")}</Button>
                    </form>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            "The member ID is the number at the end of the member page URL.",
                        )}
                    </p>
                </Panel>
            </div>

            <div className="space-y-2">
                <Panel flush title={t("Blocked members")}>
                    {blocks.data.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-muted-foreground">{t("No blocked members.")}</p>
                    ) : (
                        <List>
                            {blocks.data.map((blocked) => (
                                <ListRow key={blocked.id}>
                                    {/* Avatar identifies the member, but the name is not linked to their profile:
                                        the viewer chose to block them, so we don't surface a path back to it. */}
                                    <Avatar id={blocked.id} name={blocked.name} src={blocked.imageUrl} size="sm" decorative />
                                    <span className="min-w-0 flex-1 truncate text-foreground">{blocked.name}</span>
                                    {/* Unblock restores access (non-destructive), so it stays text-link, not destructive red. */}
                                    <Link href={`/m/block/remove/${blocked.id}`} className="shrink-0 text-sm text-link hover:underline">
                                        {t("Unblock")}
                                    </Link>
                                </ListRow>
                            ))}
                        </List>
                    )}
                </Panel>
                {blocks.data.length > 0 && <Pagination meta={blocks.meta} />}
            </div>
        </>
    );
}
