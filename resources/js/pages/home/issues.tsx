import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { CivilDate } from '@/components/timestamp';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { IssuesPageProps } from './types';

/**
 * The run of issues, newest first. The number is what an issue is called, so it is the line and the
 * link; the day it covers rides beside it, since that is what a reader looking for a particular
 * morning actually remembers.
 */
export default function HomeIssues() {
    const t = useT();
    const { issues } = usePage<IssuesPageProps>().props;

    return (
        <>
            <Head title={t('Back issues')} />

            {issues.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No issues yet.')}</p>
                </Panel>
            ) : (
                <Panel flush>
                    <List>
                        {issues.data.map((issue) => (
                            <ListRow key={issue.date} rowLink chevron>
                                <span className="min-w-0 flex-1 text-sm text-foreground">
                                    <Link href={issue.href} className={stretchedLink}>
                                        {t('No. :number', { number: issue.number })}
                                    </Link>
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    <CivilDate value={issue.date} weekday />
                                </span>
                            </ListRow>
                        ))}
                    </List>
                </Panel>
            )}

            <Pagination meta={issues.meta} />
        </>
    );
}
