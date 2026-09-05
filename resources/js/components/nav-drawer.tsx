import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Menu, UserRound } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Dialog, DialogTitle, DialogTrigger, SheetContent } from '@/components/ui/dialog';
import { Tip } from '@/components/ui/tooltip';
import { BrandMark } from '@/components/brand-mark';
import { BrandName } from '@/components/brand-name';
import { NavItems } from '@/components/nav-items';
import { lookSpec } from '@/lib/member-chrome';
import type { PageProps } from '@/types';

/**
 * The box is the whole tap target and paints nothing: 48 is the bar's height, so the target is as
 * large as it can be without reaching into the page below, and past the 44px floor the rest of the
 * app holds to.
 */
export const BAR_CONTROL =
    '-ml-1 inline-flex size-12 shrink-0 items-center justify-center rounded-full text-muted-foreground transition hover:bg-accent';

/**
 * The same box, stacked, at the bar's other end: the glyph over the word for it. A written "Menu"
 * is what carries the pattern to readers who have not learned the three lines — the reason the
 * tabbed look spends the room, since it is the look drawn for them.
 */
const BAR_CONTROL_LABELED =
    'ml-auto -mr-1 inline-flex size-12 shrink-0 flex-col items-center justify-center gap-0.5 rounded-full text-muted-foreground transition hover:bg-accent';

/** Mobile hamburger that opens a slide-in nav sheet: brand (home) + nav, and under the unified
 *  layout the account rows too — its bars carry no account menu, so the drawer is where profile and
 *  sign-out live ({@link UnifiedAccountRows}). `labeled` is the breadcrumb bar's trailing variant;
 *  the sheet follows it to the right, because a drawer opens from its trigger's side. */
export function NavDrawer({ labeled = false }: { labeled?: boolean }) {
    const t = useT();
    const { props } = usePage<PageProps>();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {labeled ? (
                // No aria-label and no Tip: the word under the glyph is the name, and one spelled
                // above it — announced or floated — would replace what the reader can see.
                <DialogTrigger className={BAR_CONTROL_LABELED}>
                    <Menu className="size-6" aria-hidden />
                    <span className="text-[11px] leading-none">{t('Menu')}</span>
                </DialogTrigger>
            ) : (
                <Tip label={t('Menu')}>
                    <DialogTrigger className={BAR_CONTROL}>
                        <Menu className="size-6" />
                    </DialogTrigger>
                </Tip>
            )}
            <SheetContent
                side={labeled ? 'right' : 'left'}
                closeLabel={t('Close')}
                // The full-bleed drawer keeps the bar's top rhythm: 4px of line, then the row.
                className={labeled ? 'pt-[calc(0.25rem+env(safe-area-inset-top))]' : undefined}
            >
                {labeled && (
                    // The bar's line continues across the drawer: opening it swaps the page under
                    // the site's colors, not the site.
                    <span aria-hidden className="absolute inset-x-0 top-[env(safe-area-inset-top)] h-1" style={{ backgroundColor: props.snsLogo.color }} />
                )}
                <DialogTitle asChild>
                    <Link
                        href="/"
                        onClick={() => setOpen(false)}
                        className={
                            labeled
                                ? // The breadcrumb bar's brand geometry, restated — same gutter, same
                                  // row — so the mark and the name hold still while the drawer opens
                                  // and closes under them.
                                  'mb-2 flex min-h-12 shrink-0 items-center gap-2'
                                : 'mb-2 flex min-h-11 items-center gap-3 rounded-full px-2 transition hover:bg-accent'
                        }
                    >
                        <BrandMark size="sm" />
                        <BrandName size={labeled ? undefined : 'lg'} className="truncate" />
                    </Link>
                </DialogTitle>
                <nav className="flex-1 overflow-y-auto">
                    <NavItems onNavigate={() => setOpen(false)} />
                </nav>
                <UnifiedAccountRows onNavigate={() => setOpen(false)} />
            </SheetContent>
        </Dialog>
    );
}

/**
 * Profile and sign-out at the drawer's foot, only under the unified chrome: its bars carry no
 * account menu (the design they follow has none), so what the avatar menu held has to live
 * somewhere, and the drawer is the one surface of the experiment that is ours to arrange.
 */
function UnifiedAccountRows({ onNavigate }: { onNavigate: () => void }) {
    const t = useT();
    const { props } = usePage<PageProps>();
    const user = props.auth.user;

    if (!lookSpec(props.look).accountInDrawer || !user) {
        return null;
    }

    const row = 'flex min-h-11 w-full items-center gap-3 rounded-full px-3 text-base text-muted-foreground transition hover:bg-accent';

    return (
        <ul className="mt-2 flex flex-col gap-1 border-t border-border pt-2">
            <li>
                <Link href={`/member/${user.id}`} onClick={onNavigate} className={row}>
                    <UserRound className="size-5 shrink-0" strokeWidth={2} />
                    <span className="flex-1 truncate">{t('Profile')}</span>
                </Link>
            </li>
            <li>
                <button type="button" onClick={() => router.post('/logout')} className={row}>
                    <LogOut className="size-5 shrink-0" strokeWidth={2} />
                    <span className="flex-1 truncate text-left">{t('Sign out')}</span>
                </button>
            </li>
        </ul>
    );
}
