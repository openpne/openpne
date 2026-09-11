import { Link } from '@inertiajs/react';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';

interface StreamEmptyProps {
    /** The head of the stream when the page was reached by a cursor; null at the head itself. */
    headUrl: string | null | undefined;
    /** What an empty head says. */
    empty: string;
    /** What a cursor page with no rows left says. */
    older: string;
}

// A cursor page whose rows are gone is not an empty stream: it says so and leads back to the head.
export function StreamEmpty({ headUrl, empty, older }: StreamEmptyProps) {
    const t = useT();

    return (
        <Panel>
            <p className="text-sm text-muted-foreground">{headUrl ? older : empty}</p>
            {headUrl && (
                <p className="mt-2 text-sm">
                    <Link href={headUrl} className="text-link hover:underline">
                        {t('Jump to latest')}
                    </Link>
                </p>
            )}
        </Panel>
    );
}
