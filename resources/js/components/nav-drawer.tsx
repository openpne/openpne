import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Menu, UserRound } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Dialog, DialogTitle, DialogTrigger, SheetContent } from '@/components/ui/dialog';
import { BrandMark } from '@/components/brand-mark';
import { BrandName } from '@/components/brand-name';
import { NavItems } from '@/components/nav-items';
import { lookSpec } from '@/lib/member-chrome';
import type { PageProps } from '@/types';

/**
 * The mobile bar's icon-control shape: a bare 24px glyph in a box as tall as the bar. The box is
 * the whole tap target and paints nothing, so the bar's only filled marks are identity (brand,
 * avatar) — a glyph and a face are never mistaken for each other the way two filled circles of
 * different diameters were. 48 is the bar's height, so the target is as large as it can be without
 * reaching into the page below, and comfortably past the 44 floor the rest of the app holds to
 * (min-h-11). Shared by the hamburger here and the detail bar's back/close control (top-nav.tsx).
 */
export const BAR_CONTROL =
    '-ml-1 inline-flex size-12 shrink-0 items-center justify-center rounded-full text-muted-foreground transition hover:bg-accent';

/** Mobile hamburger that opens a slide-in nav sheet: brand (home) + nav, and under the unified
 *  layout the account rows too — its bars carry no account menu, so the drawer is where profile and
 *  sign-out live ({@link UnifiedAccountRows}). */
export function NavDrawer() {
    const t = useT();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger aria-label={t('Menu')} className={BAR_CONTROL}>
                <Menu className="size-6" />
            </DialogTrigger>
            <SheetContent closeLabel={t('Close')}>
                <DialogTitle asChild>
                    <Link
                        href="/dashboard"
                        onClick={() => setOpen(false)}
                        className="mb-2 flex min-h-11 items-center gap-3 rounded-full px-2 transition hover:bg-accent"
                    >
                        <BrandMark size="sm" />
                        <BrandName size="lg" className="truncate" />
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
