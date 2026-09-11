import { Link } from '@inertiajs/react';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';

interface StreamEmptyProps {
    /** The head of the stream when the page was reached by a cursor; null at the head itself. */
    headUrl: string | null | undefined;
    empty: string;
    older: string;
}

export function StreamEmpty({ headUrl, empty, older }: StreamEmptyProps) {
    return (
        <Panel>
            <p className="text-sm text-muted-foreground">{headUrl ? older : empty}</p>
            <StreamHead headUrl={headUrl} className="mt-2" />
        </Panel>
    );
}

/** The way back to the head from any cursor page, rows or none (docs/internals/ordering.md, "Keyset and offset"). */
export function StreamHead({ headUrl, className }: { headUrl: string | null | undefined; className?: string }) {
    const t = useT();

    if (!headUrl) {
        return null;
    }

    return (
        <p className={className ? `${className} text-sm` : 'text-sm'}>
            <Link href={headUrl} className="text-link hover:underline">
                {t('Jump to latest')}
            </Link>
        </p>
    );
}
