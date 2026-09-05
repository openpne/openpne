import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';

/** What a roster tile needs of a member; every roster row type structurally satisfies it. */
export interface MemberTileMember {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
}

/**
 * The AiChip sits inside the link, which is what puts the fact in the link's accessible name: the
 * avatar is decorative here, so its corner tag is silent.
 */
export function MemberTile({ member, nameSize = 'xs' }: { member: MemberTileMember; nameSize?: 'xs' | 'sm' }) {
    return (
        <Link href={`/member/${member.id}`} className="flex flex-col items-center gap-1">
            <Avatar id={member.id} name={member.name} src={member.imageUrl} color={member.avatarColor} isAi={member.isAi} size="lg" decorative />
            <span className={`w-full truncate text-center ${nameSize === 'sm' ? 'text-sm' : 'text-xs'}`}>{member.name}</span>
            <AiChip isAi={member.isAi} />
        </Link>
    );
}
