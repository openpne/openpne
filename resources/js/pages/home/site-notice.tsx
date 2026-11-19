import { useId, useLayoutEffect, useRef, useState } from 'react';
import { RichBody } from '@/components/rich-body';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface SiteNoticeBody {
    body: string;
    bodyHtml: string;
}

/** Held equal to the `max-h-[192px]` the folded body gets. */
const FOLD_PX = 192;

export function SiteNotice({ notice }: { notice: SiteNoticeBody | null }) {
    const t = useT();
    const titleId = useId();
    const bodyId = useId();
    const inner = useRef<HTMLDivElement>(null);
    const [overflows, setOverflows] = useState(false);
    const [open, setOpen] = useState(false);
    const html = notice?.bodyHtml ?? null;
    const [shownHtml, setShownHtml] = useState(html);

    if (html !== shownHtml) {
        setShownHtml(html);
        setOpen(false);
    }

    useLayoutEffect(() => {
        const node = inner.current;
        if (node === null) {
            return;
        }
        const measure = () => setOverflows(node.scrollHeight > FOLD_PX + 1);
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(node);

        return () => observer.disconnect();
    }, [html]);

    if (notice === null) {
        return null;
    }

    const folded = overflows && !open;

    return (
        <section aria-labelledby={titleId}>
            <Panel title={<span id={titleId}>{t('Notice from the site')}</span>}>
                <div
                    id={bodyId}
                    className={cn('relative', folded && 'max-h-[192px] overflow-hidden')}
                    // A link inside the folded part is out of sight, so reaching it by keyboard opens the text.
                    onFocus={() => folded && setOpen(true)}
                >
                    <div ref={inner}>
                        <RichBody body={notice.body} bodyHtml={notice.bodyHtml} />
                    </div>
                    {folded && <div aria-hidden className="pointer-events-none absolute inset-x-0 bottom-0 h-12 bg-linear-to-t from-card to-transparent" />}
                </div>
                {overflows && (
                    <button
                        type="button"
                        aria-expanded={open}
                        aria-controls={bodyId}
                        onClick={() => setOpen(!open)}
                        className="mt-2 text-sm text-link hover:underline"
                    >
                        {open ? t('Show less') : t('Read more')}
                    </button>
                )}
            </Panel>
        </section>
    );
}
