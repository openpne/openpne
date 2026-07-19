import { Head, Link, router, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunityMemberRow, CommunityRoleSlug, CommunitySummary, PaginatedCommunityMembers } from './types';

interface ManageProps extends PageProps {
    community: CommunitySummary;
    members: PaginatedCommunityMembers;
    viewerRole: 'admin' | 'sub_admin'; // a plain member cannot reach this screen
    pendingAdminId: number | null; // a pending transfer nominee cannot be appointed sub-admin
}

function RoleBadge({ role }: { role: CommunityRoleSlug }) {
    const t = useT();
    return (
        <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
            {role === 'admin' ? t('Admin') : t('Sub-admin')}
        </span>
    );
}

export default function CommunityManage() {
    const t = useT();
    const confirm = useConfirm();
    const { members, community, viewerRole, pendingAdminId } = usePage<ManageProps>().props;

    const post = (path: 'appointSubAdmin' | 'demoteSubAdmin' | 'drop' | 'transferAdmin', memberId: number) =>
        router.post(`/community/member/${path}`, { id: community.id, member_id: memberId }, { preserveScroll: true });

    const appoint = async (member: CommunityMemberRow) => {
        if (await confirm({ title: t('Appoint :name as a sub-administrator of this %community%?', { name: member.name }), confirmLabel: t('Appoint') })) {
            post('appointSubAdmin', member.id);
        }
    };
    const demote = async (member: CommunityMemberRow) => {
        if (await confirm({ title: t("Demote :name from this %community%'s sub-administrator?", { name: member.name }), confirmLabel: t('Demote') })) {
            post('demoteSubAdmin', member.id);
        }
    };
    const drop = async (member: CommunityMemberRow) => {
        if (await confirm({ title: t('Drop :name from this %community%?', { name: member.name }), confirmLabel: t('Drop'), danger: true })) {
            post('drop', member.id);
        }
    };
    const transfer = async (member: CommunityMemberRow) => {
        if (await confirm({ title: t("Ask :name to take over this %community%'s administration?", { name: member.name }), confirmLabel: t('Transfer') })) {
            post('transferAdmin', member.id);
        }
    };

    return (
        <>
            <Head title={t('Management member')} />
            <Panel flush>
                <List>
                    {members.data.map((member) => {
                        const isMember = member.role === 'member';
                        // flex-wrap + a wide identity basis: on narrow screens the action buttons
                        // wrap to their own line instead of squeezing the name to a single glyph.
                        return (
                            <ListRow key={member.id} className="flex-wrap">
                                <div className="flex min-w-0 flex-1 basis-40 items-center gap-3">
                                    <Avatar id={member.id} name={member.name} src={member.imageUrl} color={member.avatarColor} size="md" decorative />
                                    <Link href={`/member/${member.id}`} className="min-w-0 flex-1 truncate text-link hover:underline">
                                        {member.name}
                                    </Link>
                                </div>
                                {/* The badge rides with the buttons so the identity box is name-only —
                                    otherwise a badge eats the name's width on narrow screens. */}
                                <div className="flex shrink-0 items-center gap-2">
                                    {!isMember && <RoleBadge role={member.role} />}
                                    {isMember && (
                                        <Button type="button" size="sm" variant="secondary" onClick={() => drop(member)}>
                                            {t('Drop')}
                                        </Button>
                                    )}
                                    {viewerRole === 'admin' && isMember && member.id !== pendingAdminId && (
                                        <Button type="button" size="sm" onClick={() => appoint(member)}>
                                            {t('Appoint')}
                                        </Button>
                                    )}
                                    {viewerRole === 'admin' && member.role === 'sub_admin' && (
                                        <Button type="button" size="sm" variant="secondary" onClick={() => demote(member)}>
                                            {t('Demote')}
                                        </Button>
                                    )}
                                    {viewerRole === 'admin' && member.id === pendingAdminId && (
                                        <span className="shrink-0 text-xs text-muted-foreground">{t('Transfer pending')}</span>
                                    )}
                                    {viewerRole === 'admin' && member.role !== 'admin' && member.id !== pendingAdminId && (
                                        <Button type="button" size="sm" variant="secondary" onClick={() => transfer(member)}>
                                            {t('Transfer')}
                                        </Button>
                                    )}
                                </div>
                            </ListRow>
                        );
                    })}
                </List>
            </Panel>
            <Pagination meta={members.meta} />
        </>
    );
}
