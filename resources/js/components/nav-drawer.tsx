import { type CSSProperties, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Menu, UserRound } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Dialog, DialogTitle, DialogTrigger, SheetContent } from '@/components/ui/dialog';
import { BrandMark } from '@/components/brand-mark';
import { BrandName } from '@/components/brand-name';
import { NavItems } from '@/components/nav-items';
import { pressPrefetch } from '@/lib/press-prefetch';
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
 * is what carries the pattern to readers who have not learned the three lines.
 */
const BAR_CONTROL_LABELED =
    'ml-auto -mr-1 inline-flex size-12 shrink-0 flex-col items-center justify-center gap-0.5 rounded-full text-muted-foreground transition hover:bg-accent';

/**
 * The phone's nav sheet: brand (home), the nav, and the account rows the bars carry no menu for.
 * It opens from the right, its trigger's side.
 */
export function NavDrawer() {
    const t = useT();
    const { props } = usePage<PageProps>();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {/* No aria-label and no Tip: the word under the glyph is the name, and one spelled above
                it — announced or floated — would replace what the reader can see. */}
            <DialogTrigger className={BAR_CONTROL_LABELED}>
                <Menu className="size-6" aria-hidden />
                <span className="text-2xs leading-none">{t('Menu')}</span>
            </DialogTrigger>
            <SheetContent
                side="right"
                closeLabel={t('Close')}
                // The full-bleed drawer keeps the bar's top rhythm: 4px of line, then the row.
                className="pt-safe-1"
            >
                {/* The bar's line continues across the drawer: opening it swaps the page under the
                    site's colors, not the site. */}
                <span aria-hidden className="absolute inset-x-0 top-safe h-1 bg-(--site-color)" style={{ '--site-color': props.snsLogo.color } as CSSProperties} />
                <DialogTitle asChild>
                    <Link
                        href="/"
                        {...pressPrefetch('/')}
                        onClick={() => setOpen(false)}
                        // The bar's mark geometry, restated — same gutter, same row — so the mark holds
                        // still while the drawer opens and closes under it.
                        className="mb-2 flex min-h-12 shrink-0 items-center gap-2"
                    >
                        <BrandMark size="sm" />
                        <BrandName className="truncate" />
                    </Link>
                </DialogTitle>
                <nav className="flex-1 overflow-y-auto">
                    <NavItems onNavigate={() => setOpen(false)} />
                </nav>
                <AccountRows onNavigate={() => setOpen(false)} />
            </SheetContent>
        </Dialog>
    );
}

/** Profile and sign-out at the drawer's foot: the phone bars carry no account menu, so what one would hold lives here. */
function AccountRows({ onNavigate }: { onNavigate: () => void }) {
    const t = useT();
    const { props } = usePage<PageProps>();
    const user = props.auth.user;

    if (!user) {
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
