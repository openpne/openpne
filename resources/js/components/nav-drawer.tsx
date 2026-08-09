import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Dialog, DialogTitle, DialogTrigger, SheetContent } from '@/components/ui/dialog';
import { BrandMark } from '@/components/brand-mark';
import { NavItems } from '@/components/nav-items';
import type { PageProps } from '@/types';

/**
 * The mobile bar's icon-control shape: a bare 24px glyph in a 44px box. The box is the whole tap
 * target and paints nothing, so the bar's only filled marks are identity (brand, avatar) — a glyph
 * and a face are never mistaken for each other the way two filled circles of different diameters
 * were. 44 is the touch floor the rest of the app holds to (min-h-11), and it is the bar's full
 * height, so the target can be that large without reaching into the page below.
 * Shared by the hamburger here and the detail bar's back/close control (top-nav.tsx).
 */
export const BAR_CONTROL =
    '-ml-1 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-muted-foreground transition hover:bg-accent';

/** Mobile hamburger that opens a slide-in nav sheet. The account menu stays in the top bar, so the
 *  sheet holds only the brand (home) and nav — no nested menu inside the dialog. */
export function NavDrawer() {
    const t = useT();
    const [open, setOpen] = useState(false);
    const { name } = usePage<PageProps>().props;

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
                        <span className="truncate text-lg font-bold">{name}</span>
                    </Link>
                </DialogTitle>
                <nav className="flex-1 overflow-y-auto">
                    <NavItems onNavigate={() => setOpen(false)} />
                </nav>
            </SheetContent>
        </Dialog>
    );
}
