import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Dialog, DialogTitle, DialogTrigger, SheetContent } from '@/components/ui/dialog';
import { BrandMark } from '@/components/brand-mark';
import { BrandName } from '@/components/brand-name';
import { NavItems } from '@/components/nav-items';

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

/** Mobile hamburger that opens a slide-in nav sheet. The account menu stays in the top bar, so the
 *  sheet holds only the brand (home) and nav — no nested menu inside the dialog. */
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
            </SheetContent>
        </Dialog>
    );
}
