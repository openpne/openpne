import { codePointLength } from '@/lib/code-points';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** OpenPNE 3's `activity_data.body` is string(140), and the server counts it in code points. */
export const TIMELINE_BODY_MAX = 140;

/** Whether the body is past the cap, counted the way the request validates it. */
export function overBodyLimit(body: string): boolean {
    return codePointLength(body) > TIMELINE_BODY_MAX;
}

/**
 * The field carries no `maxLength`: the attribute measures UTF-16 units, so it would block a body of
 * 140 astral code points that this counter and the server both accept. The submit is the gate
 * instead, so a paste that overshoots can be trimmed rather than silently truncated.
 */
export function BodyCounter({ id, body }: { id: string; body: string }) {
    const t = useT();
    const remaining = TIMELINE_BODY_MAX - codePointLength(body);

    return (
        <span id={id} className={cn('text-xs tabular-nums', remaining < 0 ? 'text-destructive' : 'text-muted-foreground')}>
            <span className="sr-only">{t('Characters left')}</span>
            {remaining}
        </span>
    );
}
