import { usePage } from '@inertiajs/react';
import { Camera, Mail, Pencil, UserPlus } from 'lucide-react';
import type { ReactNode } from 'react';
import { ActionLink } from '@/components/ui/action-link';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** The profile keys this row is drawn from — the ones the digest profile's action block reads. */
export interface MemberActionsProfile {
    id: number;
    name: string;
    isSelf: boolean;
    /** null = the viewer's own page, or a relationship with no entry to offer. */
    friendStatus: 'friend' | 'sent' | 'received' | 'none' | null;
}

/** The pill shape this row is drawn in; `!` because rounded-field would otherwise win the cascade. */
const PILL = 'rounded-full!';

/**
 * The two friend states are whole sentences, one of them carrying a member's name, so their pill has
 * to wrap — the button base would otherwise hold them on one line and push them off a phone.
 */
const SENTENCE_PILL = `${PILL} whitespace-normal text-center`;

/**
 * What the viewer can do about this member, under the hero. The same entries the digest profile
 * offers, with the same destinations and the same conditions — restyled as one centered row, because
 * here they stand where a header's actions stand rather than inside a panel of text.
 *
 * The page renders for a signed-in member only (a guest keeps the digest profile), so the viewer is
 * never a guest and the gate is the relationship alone.
 */
export function MemberActions({ profile }: { profile: MemberActionsProfile }) {
    const t = useT();
    const { enabledFeatures } = usePage<PageProps>().props;

    if (profile.isSelf) {
        return (
            <Row>
                <ActionLink href="/member/edit/profile" size="sm" className={PILL}>
                    <Pencil className="size-4" strokeWidth={2.25} aria-hidden />
                    {t('Edit Profile')}
                </ActionLink>
                <ActionLink href="/member/avatar" variant="outline" size="sm" className={PILL}>
                    <Camera className="size-4" strokeWidth={2.25} aria-hidden />
                    {t('Edit profile image')}
                </ActionLink>
            </Row>
        );
    }

    // friendStatus is already null while friends are off; 'friend' is the one status with no entry
    // to offer, so listing the states keeps the row from rendering empty.
    const friendEntry = enabledFeatures.friend && ['none', 'sent', 'received'].includes(profile.friendStatus ?? '');
    const messageEntry = enabledFeatures.directMessage;

    if (!friendEntry && !messageEntry) {
        return null;
    }

    return (
        <Row>
            {friendEntry && (
                <>
                    {profile.friendStatus === 'none' && (
                        <ActionLink href={`/friend/link?id=${profile.id}`} size="sm" className={PILL}>
                            <UserPlus className="size-4" strokeWidth={2.25} aria-hidden />
                            {t('Send a %friend% request')}
                        </ActionLink>
                    )}
                    {/* A request already sent is a state, not an errand: quiet, and it leads to the
                        one screen where it can be taken back. One received is the errand. */}
                    {profile.friendStatus === 'sent' && (
                        <ActionLink href="/friend/requests" variant="outline" size="sm" className={SENTENCE_PILL}>
                            {t('%Friend% request pending.')}
                        </ActionLink>
                    )}
                    {profile.friendStatus === 'received' && (
                        <ActionLink href="/friend/requests" size="sm" className={SENTENCE_PILL}>
                            {t(':name sent you a %friend% request.', { name: profile.name })}
                        </ActionLink>
                    )}
                </>
            )}
            {messageEntry && (
                <ActionLink href={`/messages/${profile.id}`} variant="outline" size="sm" className={PILL}>
                    <Mail className="size-4" strokeWidth={2.25} aria-hidden />
                    {t('Send a message')}
                </ActionLink>
            )}
        </Row>
    );
}

function Row({ children }: { children: ReactNode }) {
    return <div className="mt-3 flex flex-wrap items-center justify-center gap-2">{children}</div>;
}
