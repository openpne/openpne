import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AiChip } from '@/components/ai-chip';
import { InitialBadge } from '@/components/initial-badge';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { HOME_CARD } from './home-section';
import { coverGradientStyle, derivedIdentityColor } from './identity-visual';

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
    /** Only where the page is about somebody else, and only inside their age gate. */
    age?: number | null;
}

/** The content column is `max-w-2xl`; below that the cover runs the full width of the screen. */
const COVER_SIZES = '(min-width: 42rem) 42rem, 100vw';

/**
 * Who the page is about.
 *
 * The member's picture is the cover rather than a face beside the name: a home someone opens dozens
 * of times a day should look like theirs before a word of it is read, and a page about another member
 * is about them before it is read either. The arc is what keeps it a header and not a banner — the
 * card rises back over the photo's foot, so the name sits on the card rather than on the picture, and
 * no text ever has to survive whatever was uploaded behind it.
 *
 * `selfLink` is the home's way through to the profile; a page that already is the profile passes
 * `actions` instead — what the viewer can do about this member, where the link would have been — and
 * `as="h1"`, since there the name is what the page is called rather than a block inside it.
 *
 * `meta` is the line of facts about the subject that belongs with its name (a group's category and
 * size), and `clampBio` decides whether the self-introduction is a two-line lead-in or the whole
 * text: a member's page has their profile below to read the rest of them in, a group's page has
 * nothing else that says what the group is for.
 */
export function ProfileHeader({
    profile,
    as = 'h2',
    selfLink,
    actions,
    meta,
    clampBio = true,
}: {
    profile: UnifiedProfile;
    as?: 'h1' | 'h2';
    selfLink?: boolean;
    actions?: ReactNode;
    meta?: ReactNode;
    clampBio?: boolean;
}) {
    const t = useT();

    return (
        <section className={HOME_CARD}>
            {/* The dome and the name it stores live over whichever cover renders, so the two covers
                stay covers and the identity geometry is written once. */}
            <div className="relative">
                {profile.avatarUrl ? (
                    <img
                        src={profile.avatarUrl}
                        srcSet={profile.avatarUrlLarge ? `${profile.avatarUrl} 640w, ${profile.avatarUrlLarge} 1200w` : undefined}
                        sizes={COVER_SIZES}
                        alt=""
                        // A square crop laid out landscape: the shape is this page's, so the height cap
                        // travels in the box (aspect + max-height) and object-cover does the cutting.
                        className="aspect-[4/3] max-h-[21.25rem] w-full object-cover sm:max-h-72"
                    />
                ) : (
                    // No picture still gets a cover: a wash of the identity's color (chosen, or
                    // derived from the name), the initial standing in the area the dome leaves
                    // visible — the page reads designed rather than missing something.
                    <div
                        aria-hidden
                        className="flex aspect-[4/3] max-h-[16rem] w-full items-center justify-center pb-24 sm:max-h-60"
                        style={coverGradientStyle(profile.avatarColor ?? derivedIdentityColor(profile.name))}
                    >
                        <InitialBadge
                            aria-hidden
                            name={profile.name}
                            className="size-20 rounded-full bg-scrim-foreground/25 text-3xl text-scrim-foreground"
                        />
                    </div>
                )}
                {/* The design's bowl, measured off the mock (590x369): apex ≈ 39% of the cover's
                    height, meeting the card's edges ≈ 19% up — a half-ellipse a little wider than
                    the card (≈114%), so the picture ends where the curve crosses the edge and the
                    skirt below is white the full width. -bottom-px closes the seam a fractional
                    height can open. */}
                {/* rounded-t-full would clamp to quarter-circles with a flat middle (the radii scale by the
                    SMALLEST side ratio); the explicit 50%/100% pair keeps the whole top one ellipse. */}
                <span aria-hidden className="absolute inset-x-[-7%] -bottom-px block h-26 rounded-t-[50%_100%] bg-card" />
                {/* The name is stored inside the curve — its row sits between the dome's apex and
                    the photo's side bottoms, so the picture still shows either side of it. */}
                <div className="absolute inset-x-[16%] bottom-14 flex min-w-0 items-center justify-center gap-2">
                    <Heading as={as} variant="display" className="min-w-0 truncate">
                        {profile.name}
                    </Heading>
                    <AiChip isAi={profile.isAi} />
                </div>
            </div>

            <div className="px-4 pt-1 pb-4 text-center sm:px-5">

                {profile.age !== null && profile.age !== undefined && (
                    <p className="mt-0.5 text-sm text-muted-foreground">{t(':age years old', { age: profile.age })}</p>
                )}

                {meta}

                {profile.bio && (
                    <p className={cn('mt-1 break-words text-sm text-muted-foreground', clampBio ? 'line-clamp-2' : 'whitespace-pre-wrap')}>
                        <UserText text={profile.bio} />
                    </p>
                )}

                {selfLink && (
                    <Link href={`/member/${profile.id}`} className="mt-3 inline-block text-sm text-link hover:underline">
                        {t('View my profile')}
                    </Link>
                )}

                {actions}
            </div>
        </section>
    );
}
