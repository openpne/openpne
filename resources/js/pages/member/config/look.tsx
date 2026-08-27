import { useForm } from '@inertiajs/react';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { FormActions, RadioCardGroup } from '@/components/ui/field';
import { RadioCard } from '@/components/ui/radio-card';
import { useT } from '@/lib/i18n';
import type { LookId } from '@/lib/member-chrome';

/** The choice that means "no look of my own" (App\Http\Requests\Member\UpdateLookRequest). */
const FOLLOW_DEFAULT = 'default';

/**
 * What each look does about one dimension of the layout, as translation keys. This table is the
 * page: a look is a way around the site, and showing one live said nothing about what to look at
 * (docs/internals/looks.md), so what separates them is stated instead.
 *
 * Cells are keyed by look id — `Record<LookId, …>` — so a look joining the registry cannot ship a
 * column of blanks. Columns come from the selectable set, not from this map, so a look the site
 * does not offer is never described as if it could be chosen.
 */
const COMPARISON: { dimension: string; cells: Record<LookId, string> }[] = [
    {
        dimension: 'Page structure',
        cells: {
            standard: 'The Modern layout as it has been.',
            unified: 'An experiment: member pages and %community% pages take the same two parts (profile → latest).',
            tabbed: 'The same page structure as Unified. Only the frame around it — the header and the navigation — differs.',
        },
    },
    {
        dimension: 'Home',
        cells: {
            standard: 'A digest: lists of notices, talk and %diaries%.',
            unified: 'The same as Standard.',
            tabbed: 'The same as Standard.',
        },
    },
    {
        dimension: 'Header on a phone',
        cells: {
            standard: 'Varies by screen: a title on a list, back plus the name on a detail page.',
            unified: 'At the top level, Home / %communities% tabs and a notification bell. Deeper screens are as Standard.',
            tabbed: 'One grammar everywhere: the site mark, where you are (a breadcrumb; the site name on home and on a member or %community% top), and the menu, under a line in the site color. No bell, no back button.',
        },
    },
    {
        dimension: 'Bottom bar on a phone',
        cells: {
            standard: 'Four labelled tabs: Home, %communities%, %diaries%, notifications. Messages moves into the menu.',
            unified: 'Three parts: search | where you are | notifications.',
            tabbed: 'The same as Standard.',
        },
    },
    {
        dimension: 'Talk screen',
        cells: {
            standard: 'No bottom bar.',
            unified: 'No bottom bar.',
            tabbed: 'The tabs stand while you read, and slide away once you start typing.',
        },
    },
    {
        dimension: 'Menu (☰)',
        cells: {
            standard: 'Opens from the left.',
            unified: 'Opens from the left, with account actions at the foot.',
            tabbed: 'Opens full-width from the right, with account actions at the foot.',
        },
    },
    {
        dimension: 'Notification mark',
        cells: {
            standard: 'A number badge.',
            unified: 'A dot.',
            tabbed: 'A dot, on the notifications tab only.',
        },
    },
    {
        dimension: 'On a desktop',
        cells: {
            standard: 'Sidebar plus a right column (%friends% and such).',
            unified: 'Home / %communities% tabs beside the sidebar. No right column.',
            tabbed: 'No header: the site-color line over the sidebar, and a place bar on deeper screens.',
        },
    },
];

interface Option {
    value: LookId;
    label: string;
    description?: string;
}

interface Props {
    // Named to stand beside the shared `look` (the active look id), which it must not shadow —
    // page props win the merge, and an object where the shell expects an id is a blank page.
    lookChoice: {
        options: Option[];
        /** The stored choice, null while the member follows the site default. */
        current: LookId | null;
        default: { value: LookId; label: string };
    };
}

export default function ConfigLook({ lookChoice }: Props) {
    const t = useT();
    // The saved state is the form's baseline, so `isDirty` is exactly "differs from what is saved".
    const form = useForm({ choice: lookChoice.current ?? FOLLOW_DEFAULT });

    return (
        <SettingsSubpage title={t('Layout')}>
            <div className="space-y-6">
                <p className="text-sm text-muted-foreground">
                    {t('A layout changes how the site is arranged around you, not what you can do here.')}
                </p>

                {/* Three columns of description do not fit a phone, and narrowing them to fit would
                    cost the detail the table exists to give, so it scrolls sideways instead. A
                    scrollable region needs a name and the keyboard's reach, hence role + tabIndex. */}
                <div
                    role="region"
                    aria-label={t('How the layouts differ')}
                    tabIndex={0}
                    className="overflow-x-auto rounded-field border border-border"
                >
                    {/* 36rem: three look columns and the dimension stub fit the content column at
                        lg without the scroll a phone still gets. */}
                    <table className="w-full min-w-[36rem] border-collapse text-left text-sm">
                        <caption className="sr-only">{t('How the layouts differ')}</caption>
                        <thead>
                            <tr className="border-b border-border bg-muted">
                                <th scope="col" className="px-3 py-2 align-bottom text-muted-foreground">
                                    {t('What changes')}
                                </th>
                                {lookChoice.options.map((opt) => (
                                    <th key={opt.value} scope="col" className="px-3 py-2 align-bottom text-foreground">
                                        {t(opt.label)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {COMPARISON.map((row) => (
                                <tr key={row.dimension} className="border-b border-border last:border-0">
                                    <th scope="row" className="px-3 py-2 align-top text-muted-foreground">
                                        {t(row.dimension)}
                                    </th>
                                    {lookChoice.options.map((opt) => (
                                        <td key={opt.value} className="px-3 py-2 align-top text-foreground">
                                            {t(row.cells[opt.value])}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/member/config/look');
                    }}
                >
                    <div className="space-y-4">
                        <RadioCardGroup legend={t('Layout')} error={form.errors.choice}>
                            {/* Following the site default leads, and is where an undecided member
                                starts — naming the current default so the choice is not between a
                                look and an abstraction. */}
                            <RadioCard
                                name="choice"
                                value={FOLLOW_DEFAULT}
                                checked={form.data.choice === FOLLOW_DEFAULT}
                                onChange={(e) => form.setData('choice', e.target.value)}
                                label={t('Match the site default (currently :look)', { look: t(lookChoice.default.label) })}
                            />
                            {lookChoice.options.map((opt) => (
                                <RadioCard
                                    key={opt.value}
                                    name="choice"
                                    value={opt.value}
                                    checked={form.data.choice === opt.value}
                                    onChange={(e) => form.setData('choice', e.target.value)}
                                    label={t(opt.label)}
                                    description={opt.description ? t(opt.description) : undefined}
                                />
                            ))}
                        </RadioCardGroup>
                        <FormActions>
                            {/* Deliberate, like the surface switch: the whole shell re-renders in the
                                chosen look, so it must not fire on a stray radio click. */}
                            <Button type="submit" loading={form.processing} disabled={!form.isDirty}>
                                {t('Switch to this layout')}
                            </Button>
                        </FormActions>
                    </div>
                </form>
            </div>
        </SettingsSubpage>
    );
}
