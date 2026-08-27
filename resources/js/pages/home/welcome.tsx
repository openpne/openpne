import { Link } from '@inertiajs/react';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * What the home says to a member with nothing to read yet: the next steps that end the empty state,
 * one per unit that is switched on.
 */
export function WelcomePanel({ name, enabledFeatures }: { name: string; enabledFeatures: PageProps['enabledFeatures'] }) {
    const t = useT();

    return (
        <Panel title={t('Welcome, :name.', { name })}>
            <p className="text-sm text-muted-foreground">{t('Find people and places to fill your home.')}</p>
            <div className="mt-4">
                <List>
                    <ListRow rowLink chevron>
                        <span className="min-w-0 flex-1 text-sm text-foreground">
                            <Link href="/member/search" className={stretchedLink}>
                                {t('Search members')}
                            </Link>
                        </span>
                    </ListRow>
                    {enabledFeatures.group && (
                        <ListRow rowLink chevron>
                            <span className="min-w-0 flex-1 text-sm text-foreground">
                                <Link href="/groups" className={stretchedLink}>
                                    {t('Search %communities%')}
                                </Link>
                            </span>
                        </ListRow>
                    )}
                    {enabledFeatures.diary && (
                        <ListRow rowLink chevron>
                            <span className="min-w-0 flex-1 text-sm text-foreground">
                                <Link href="/diary/new" className={stretchedLink}>
                                    {t('Post %diary%')}
                                </Link>
                            </span>
                        </ListRow>
                    )}
                </List>
            </div>
        </Panel>
    );
}
