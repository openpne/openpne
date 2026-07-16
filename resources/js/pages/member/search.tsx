import { Head, router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { AgeRange, MemberRow, MonthDayRange, SearchCriteria, SearchFormField } from './types';

interface SearchProps extends PageProps {
    profiles: SearchFormField[];
    members: { data: MemberRow[]; meta: PaginationMeta };
    criteria: SearchCriteria;
    showAge: boolean;
}

export default function MemberSearch() {
    const t = useT();
    const { profiles, members, criteria, showAge } = usePage<SearchProps>().props;

    const [name, setName] = useState(criteria.name ?? '');
    const [profile, setProfile] = useState<Record<string, string | string[]>>(criteria.profile ?? {});
    const [date, setDate] = useState<Record<string, { from?: string; to?: string }>>(criteria.date ?? {});
    const [monthday, setMonthday] = useState<Record<string, MonthDayRange>>(criteria.monthday ?? {});
    const [age, setAge] = useState<AgeRange>(criteria.age ?? {});

    // Land with the detailed criteria expanded only when the applied search actually used one, so the
    // filters that produced the current results stay visible while the common name-only case stays lean.
    const hasAdvancedCriteria =
        Object.values(criteria.profile ?? {}).some((v) => (Array.isArray(v) ? v.length > 0 : Boolean(v))) ||
        Object.values(criteria.date ?? {}).some((r) => Boolean(r?.from || r?.to)) ||
        Object.values(criteria.monthday ?? {}).some((m) => Boolean(m) && Object.values(m).some(Boolean)) ||
        Boolean(criteria.age?.min || criteria.age?.max);
    const [advancedOpen, setAdvancedOpen] = useState(hasAdvancedCriteria);
    const [searching, setSearching] = useState(false);

    const setField = (id: number, value: string | string[]) => setProfile((p) => ({ ...p, [id]: value }));
    const setRange = (id: number, key: 'from' | 'to', value: string) =>
        setDate((d) => ({ ...d, [id]: { ...d[id], [key]: value } }));
    const setMonthDay = (id: number, key: keyof MonthDayRange, value: string) =>
        setMonthday((m) => ({ ...m, [id]: { ...m[id], [key]: value } }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get('/member/search', { name, profile, date, monthday, age }, {
            preserveState: false,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    return (
        <>
            <Head title={t('Member search')} />
            <form onSubmit={submit} className="space-y-3">
                {/* Card-less pill: the common case is a quick name lookup, so the search stays
                    subordinate to the results list below. The magnifier submits the whole form. */}
                <div className="relative">
                    <label htmlFor="search_name" className="sr-only">
                        {t('%nickname%')}
                    </label>
                    <Input
                        id="search_name"
                        type="search"
                        enterKeyHint="search"
                        placeholder={t('Search by %nickname%')}
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        className="rounded-full pr-11 pl-5"
                    />
                    <SearchSubmitButton loading={searching} />
                </div>

                <button
                    type="button"
                    onClick={() => setAdvancedOpen((o) => !o)}
                    aria-expanded={advancedOpen}
                    className="flex items-center gap-1 text-sm text-link hover:underline"
                >
                    <ChevronRight className={cn('size-4 transition-transform', advancedOpen && 'rotate-90')} aria-hidden />
                    {t('Advanced search')}
                </button>

                {advancedOpen && (
                    <Panel bodyClassName="space-y-4">
                        {profiles.map((field) => (
                            <SearchField
                                key={field.id}
                                field={field}
                                value={profile[field.id]}
                                range={date[field.id]}
                                monthDay={monthday[field.id]}
                                onValue={(v) => setField(field.id, v)}
                                onRange={(k, v) => setRange(field.id, k, v)}
                                onMonthDay={(k, v) => setMonthDay(field.id, k, v)}
                            />
                        ))}

                        {/* Derived age, gated by AgeVisibility (separate from the birthday field). */}
                        {showAge && (
                            <fieldset className="space-y-1.5">
                                <legend className="text-sm font-medium text-foreground">{t('Age')}</legend>
                                <div className="flex items-center gap-2">
                                    <Input
                                        type="number"
                                        min={0}
                                        className="w-24"
                                        aria-label={`${t('Age')} ${t('Start')}`}
                                        value={age.min ?? ''}
                                        onChange={(e) => setAge((a) => ({ ...a, min: e.target.value }))}
                                    />
                                    <span className="text-muted-foreground">–</span>
                                    <Input
                                        type="number"
                                        min={0}
                                        className="w-24"
                                        aria-label={`${t('Age')} ${t('End')}`}
                                        value={age.max ?? ''}
                                        onChange={(e) => setAge((a) => ({ ...a, max: e.target.value }))}
                                    />
                                </div>
                            </fieldset>
                        )}

                        <Button type="submit" loading={searching}>{t('Search')}</Button>
                    </Panel>
                )}
            </form>

            <section className="space-y-3">
                <Panel flush title={t('Search Results')}>
                    {members.data.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-muted-foreground">{t('No members found.')}</p>
                    ) : (
                        <List>
                            {members.data.map((member) => (
                                <ListRow
                                    key={member.id}
                                    href={`/member/${member.id}`}
                                    chevron
                                    // Top-align only when a self-introduction adds a second line; single-line rows stay centered.
                                    className={member.selfIntroduction ? 'items-start' : undefined}
                                >
                                    <Avatar id={member.id} name={member.name} src={member.imageUrl} color={member.avatarColor} size="md" decorative />
                                    <div className="min-w-0 flex-1">
                                        <span className="block truncate text-foreground">{member.name}</span>
                                        {member.selfIntroduction && (
                                            <span className="mt-0.5 line-clamp-2 text-sm text-muted-foreground">
                                                {member.selfIntroduction}
                                            </span>
                                        )}
                                    </div>
                                </ListRow>
                            ))}
                        </List>
                    )}
                </Panel>
                {members.data.length > 0 && <Pagination meta={members.meta} />}
            </section>
        </>
    );
}

interface SearchFieldProps {
    field: SearchFormField;
    value: string | string[] | undefined;
    range: { from?: string; to?: string } | undefined;
    monthDay: MonthDayRange | undefined;
    onValue: (value: string | string[]) => void;
    onRange: (key: 'from' | 'to', value: string) => void;
    onMonthDay: (key: keyof MonthDayRange, value: string) => void;
}

function SearchField({ field, value, range, monthDay, onValue, onRange, onMonthDay }: SearchFieldProps) {
    const t = useT();
    const scalar = typeof value === 'string' ? value : '';
    const selected = Array.isArray(value) ? value : [];
    const id = `search-${field.id}`;

    // Multi-control fields (birthday/date ranges, a checkbox set) are a fieldset with a legend and
    // per-control names; single-control fields are one control that the caption labels via Field.
    switch (field.formType) {
        case 'birthday':
            // Month/day only; the birth year (= age) is searched via the Age field.
            return (
                <fieldset className="space-y-1.5">
                    <legend className="text-sm font-medium text-foreground">{field.caption}</legend>
                    <div className="flex flex-wrap items-center gap-2">
                        {(['from', 'to'] as const).map((bound) => (
                            <span key={bound} className="flex items-center gap-1">
                                <Select
                                    className="w-auto"
                                    aria-label={`${field.caption} ${t(bound === 'from' ? 'Start' : 'End')} ${t('Month')}`}
                                    value={monthDay?.[`${bound}_month`] ?? ''}
                                    onChange={(e) => onMonthDay(`${bound}_month`, e.target.value)}
                                >
                                    <option value="">{t('Month')}</option>
                                    {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                                        <option key={m} value={m}>{m}</option>
                                    ))}
                                </Select>
                                <Select
                                    className="w-auto"
                                    aria-label={`${field.caption} ${t(bound === 'from' ? 'Start' : 'End')} ${t('Day')}`}
                                    value={monthDay?.[`${bound}_day`] ?? ''}
                                    onChange={(e) => onMonthDay(`${bound}_day`, e.target.value)}
                                >
                                    <option value="">{t('Day')}</option>
                                    {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => (
                                        <option key={d} value={d}>{d}</option>
                                    ))}
                                </Select>
                                {bound === 'from' && <span className="text-muted-foreground">–</span>}
                            </span>
                        ))}
                    </div>
                </fieldset>
            );

        case 'checkbox':
            return (
                <fieldset className="space-y-1.5">
                    <legend className="text-sm font-medium text-foreground">{field.caption}</legend>
                    <div className="space-y-1.5">
                        {field.options.map((opt) => (
                            <label key={opt.id} className="flex items-center gap-2 text-sm text-foreground">
                                <Checkbox
                                    checked={selected.includes(opt.id)}
                                    onChange={() =>
                                        onValue(selected.includes(opt.id) ? selected.filter((v) => v !== opt.id) : [...selected, opt.id])
                                    }
                                />
                                {opt.caption}
                            </label>
                        ))}
                    </div>
                </fieldset>
            );

        case 'date':
            return (
                <fieldset className="space-y-1.5">
                    <legend className="text-sm font-medium text-foreground">{field.caption}</legend>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input type="date" className="w-auto" aria-label={`${field.caption} ${t('Start')}`} value={range?.from ?? ''} onChange={(e) => onRange('from', e.target.value)} />
                        <span className="text-muted-foreground">–</span>
                        <Input type="date" className="w-auto" aria-label={`${field.caption} ${t('End')}`} value={range?.to ?? ''} onChange={(e) => onRange('to', e.target.value)} />
                    </div>
                </fieldset>
            );

        case 'select':
        case 'radio':
            return (
                <Field label={field.caption} htmlFor={id}>
                    <Select value={scalar} onChange={(e) => onValue(e.target.value)}>
                        <option value="">{t('Any')}</option>
                        {field.options.map((opt) => (
                            <option key={opt.id} value={opt.id}>{opt.caption}</option>
                        ))}
                    </Select>
                </Field>
            );

        case 'country_select':
            return (
                <Field label={field.caption} htmlFor={id}>
                    <Select value={scalar} onChange={(e) => onValue(e.target.value)}>
                        <option value="">{t('Any')}</option>
                        {field.countries?.map((c) => (
                            <option key={c.value} value={c.value}>{c.label}</option>
                        ))}
                    </Select>
                </Field>
            );

        case 'region_select': {
            const groups = field.regions ?? [];
            const grouped = (groups[0]?.country ?? '') !== '';
            return (
                <Field label={field.caption} htmlFor={id}>
                    <Select value={scalar} onChange={(e) => onValue(e.target.value)}>
                        <option value="">{t('Any')}</option>
                        {grouped
                            ? groups.map((g) => (
                                <optgroup key={g.country} label={g.country}>
                                    {g.options.map((o) => (
                                        <option key={o.value} value={o.value}>{o.label}</option>
                                    ))}
                                </optgroup>
                            ))
                            : groups[0]?.options.map((o) => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                    </Select>
                </Field>
            );
        }

        default:
            return (
                <Field label={field.caption} htmlFor={id}>
                    <Input type="text" value={scalar} onChange={(e) => onValue(e.target.value)} />
                </Field>
            );
    }
}
