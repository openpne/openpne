import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Dialog, DialogTitle, DialogTrigger, SheetContent } from '@/components/ui/dialog';
import { BrandMark } from '@/components/brand-mark';
import { NavItems } from '@/components/nav-items';
import type { PageProps } from '@/types';

/** Mobile hamburger that opens a slide-in nav sheet. The account menu stays in the top bar, so the
 *  sheet holds only the brand (home) and nav — no nested menu inside the dialog. */
export function NavDrawer() {
    const t = useT();
    const [open, setOpen] = useState(false);
    const { name } = usePage<PageProps>().props;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger
                aria-label={t('Menu')}
                className="-ml-1 inline-flex size-10 items-center justify-center rounded-full text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
            >
                <Menu className="size-6" />
            </DialogTrigger>
            <SheetContent closeLabel={t('Close')}>
                <DialogTitle asChild>
                    <Link
                        href="/dashboard"
                        onClick={() => setOpen(false)}
                        className="mb-2 flex min-h-11 items-center gap-3 rounded-full px-2 transition hover:bg-slate-100 dark:hover:bg-slate-800"
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
