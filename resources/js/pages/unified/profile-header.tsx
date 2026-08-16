import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { InitialBadge } from '@/components/initial-badge';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { HOME_CARD } from './home-section';

export interface UnifiedProfile {
    id: number;
    name: string;
    /** The two `srcset` rungs of the cover crop; both null when the member set no picture. */
    avatarUrl: string | null;
    avatarUrlLarge: string | null;
    avatarColor: string | null;
    isAi: boolean;
    /** Self-introduction, promoted into the header; null when unset. */
    bio: string | null;
}

/** The content column is `max-w-2xl`; below that the cover runs the full width of the screen. */
const COVER_SIZES = '(min-width: 42rem) 42rem, 100vw';

/**
 * Who the page is about — the viewer themselves. The page's own h1 is the sr-only "Home", so the
 * name is an h2 however large it is painted.
 *
 * The member's picture is the cover rather than a face beside the name: a home someone opens dozens
 * of times a day should look like theirs before a word of it is read. The arc is what keeps it a
 * header and not a banner — the card rises back over the photo's foot, so the name sits on the card
 * rather than on the picture, and no text ever has to survive whatever was uploaded behind it.
 */
export function ProfileHeader({ profile }: { profile: UnifiedProfile }) {
    const t = useT();

    return (
        <section className={HOME_CARD}>
            {profile.avatarUrl ? (
                <div className="relative">
                    <img
                        src={profile.avatarUrl}
                        srcSet={profile.avatarUrlLarge ? `${profile.avatarUrl} 640w, ${profile.avatarUrlLarge} 1200w` : undefined}
                        sizes={COVER_SIZES}
                        alt=""
                        // A square crop laid out landscape: the shape is this page's, so the height cap
                        // travels in the box (aspect + max-height) and object-cover does the cutting.
                        className="aspect-[4/3] max-h-[21.25rem] w-full object-cover sm:max-h-72"
                    />
                    {/* Wider than the card so the ellipse's ends leave the frame instead of meeting it;
                        the card clips them. -bottom-px closes the seam a fractional height can open. */}
                    <span aria-hidden className="absolute inset-x-[-10%] -bottom-px block h-10 rounded-t-[50%] bg-card" />
                </div>
            ) : (
                // No cover without a picture: a placeholder band would be a photo-shaped hole. The
                // badge stands in the header's own space instead.
                <div className="mt-6 flex justify-center">
                    <InitialBadge aria-hidden name={profile.name} color={profile.avatarColor} className="size-24 rounded-full text-3xl" />
                </div>
            )}

            <div className="px-4 pt-2 pb-4 text-center sm:px-5">
                <div className="flex min-w-0 items-center justify-center gap-2">
                    <Heading as="h2" variant="page">
                        {profile.name}
                    </Heading>
                    <AiChip isAi={profile.isAi} />
                </div>

                {profile.bio && (
                    <p className="mt-1 line-clamp-2 break-words text-sm text-muted-foreground">
                        <UserText text={profile.bio} />
                    </p>
                )}

                <Link href={`/member/${profile.id}`} className="mt-3 inline-block text-sm text-link hover:underline">
                    {t('View my profile')}
                </Link>
            </div>
        </section>
    );
}
