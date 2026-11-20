import { CONTENT_KINDS, type ContentKind } from '@/lib/content-kinds';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Phrasing text, never a link, so it can sit inside a block that is one link or one button (docs/internals/modern-frame.md, "Content kinds"). */
export function KindMark({ kind, group, className }: { kind: ContentKind; group?: string; className?: string }) {
    const t = useT();
    const { icon: Icon, label } = CONTENT_KINDS[kind];

    return (
        <span className={cn('flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground', className)}>
            <Icon className="size-3.5 shrink-0" aria-hidden />
            <span className="shrink-0">{t(label)}</span>
            {group !== undefined && (
                <>
                    <span aria-hidden>·</span>
                    <span className="min-w-0 truncate">{group}</span>
                </>
            )}
        </span>
    );
}
