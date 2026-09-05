import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { CivilDate } from '@/components/timestamp';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { IssuesPageProps } from './types';

/** A day is called by its date, so the date is both the line and the link. */
export default function HomeIssues() {
    const t = useT();
    const { issues } = usePage<IssuesPageProps>().props;

    return (
        <>
            <Head title={t('Past happenings')} />

            {issues.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('Nothing yet.')}</p>
                </Panel>
            ) : (
                <Panel flush>
                    <List>
                        {issues.data.map((issue) => (
                            <ListRow key={issue.date} rowLink chevron>
                                <span className="min-w-0 flex-1 text-sm text-foreground">
                                    <Link href={issue.href} className={stretchedLink}>
                                        <CivilDate value={issue.date} weekday />
                                    </Link>
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
