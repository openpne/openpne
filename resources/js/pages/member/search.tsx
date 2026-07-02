import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { AgeRange, MemberRow, MonthDayRange, SearchCriteria, SearchFormField } from './types';

interface SearchProps extends PageProps {
    profiles: SearchFormField[];
    members: { data: MemberRow[]; meta: PaginationMeta };
    criteria: SearchCriteria;
}

export default function MemberSearch() {
    const t = useT();
    const { profiles, members, criteria } = usePage<SearchProps>().props;

    const [name, setName] = useState(criteria.name ?? '');
    const [profile, setProfile] = useState<Record<string, string | string[]>>(criteria.profile ?? {});
    const [date, setDate] = useState<Record<string, { from?: string; to?: string }>>(criteria.date ?? {});
    const [monthday, setMonthday] = useState<Record<string, MonthDayRange>>(criteria.monthday ?? {});
    const [age, setAge] = useState<AgeRange>(criteria.age ?? {});

    const setField = (id: number, value: string | string[]) => setProfile((p) => ({ ...p, [id]: value }));
    const setRange = (id: number, key: 'from' | 'to', value: string) =>
        setDate((d) => ({ ...d, [id]: { ...d[id], [key]: value } }));
    const setMonthDay = (id: number, key: keyof MonthDayRange, value: string) =>
        setMonthday((m) => ({ ...m, [id]: { ...m[id], [key]: value } }));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        router.get('/m/member/search', { name, profile, date, monthday, age }, { preserveState: false });
    };

    return (
        <>
            <Head title={t('Member search')} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{t('Member search')}</h1>

                <form onSubmit={submit} className="space-y-4">
                    <Field label={t('%nickname%')} htmlFor="search_name">
                        <Input id="search_name" type="text" value={name} onChange={(e) => setName(e.target.value)} />
                    </Field>

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

                    <Button type="submit">{t('Search')}</Button>
                </form>

                <section className="space-y-3">
                    <h2 className="text-lg font-medium text-foreground">{t('Search Results')}</h2>
                    {members.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No members found.')}</p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {members.data.map((member) => (
                                <li key={member.id} className="py-2">
                                    <Link href={`/m/member/${member.id}`} className="flex items-center gap-3 text-foreground hover:underline">
                                        {member.avatarUrl && (
                                            <img src={member.avatarUrl} alt="" className="size-10 rounded-md object-cover" />
                                        )}
                                        <span className="truncate">{member.name}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Pagination meta={members.meta} />
                </section>
            </main>
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
