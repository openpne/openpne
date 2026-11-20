import { CONTENT_KINDS, type ContentKind } from '@/lib/content-kinds';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Text, never a link, so it can sit inside a block that is one link (docs/internals/home-issues.md, "Rendering"). */
export function KindMark({ kind, group, className }: { kind: ContentKind; group?: string; className?: string }) {
    const t = useT();
    const { icon: Icon, label } = CONTENT_KINDS[kind];

    return (
        <p className={cn('flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground', className)}>
            <Icon className="size-3.5 shrink-0" aria-hidden />
            <span className="shrink-0">{t(label)}</span>
            {group !== undefined && (
                <>
                    <span aria-hidden>·</span>
                    <span className="min-w-0 truncate">{group}</span>
                </>
            )}
        </p>
    );
}
