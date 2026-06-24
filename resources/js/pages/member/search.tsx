import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Pagination, type PaginationMeta } from '@/components/pagination';
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

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/m/member/search', { name, profile, date, monthday, age }, { preserveState: false });
    };

    return (
        <>
            <Head title={t('Member search')} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                <h1 className="text-xl font-semibold">{t('Member search')}</h1>

                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <label htmlFor="search_name">{t('%nickname%')}</label>
                        <input id="search_name" type="text" value={name} onChange={(e) => setName(e.target.value)} />
                    </div>

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
                    <div>
                        <label htmlFor="age_min">{t('Age')}</label>
                        <span className="flex items-center gap-2">
                            <input
                                id="age_min"
                                type="number"
                                min={0}
                                value={age.min ?? ''}
                                onChange={(e) => setAge((a) => ({ ...a, min: e.target.value }))}
                            />
                            <span>–</span>
                            <input
                                type="number"
                                min={0}
                                value={age.max ?? ''}
                                onChange={(e) => setAge((a) => ({ ...a, max: e.target.value }))}
                            />
                        </span>
                    </div>

                    <button type="submit">{t('Search')}</button>
                </form>

                <section className="space-y-3">
                    <h2 className="text-lg font-medium">{t('Results')}</h2>
                    {members.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No members found.')}</p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {members.data.map((member) => (
                                <li key={member.id} className="py-2">
                                    <Link href={`/m/member/${member.id}`} className="flex items-center gap-3">
                                        {member.avatarUrl && (
                                            <img src={member.avatarUrl} alt={member.name} className="size-10 rounded object-cover" />
                                        )}
                                        <span>{member.name}</span>
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

    const render = () => {
        switch (field.formType) {
            case 'birthday':
                // Month/day only; the birth year (= age) is searched via the Age field.
                return (
                    <span className="flex items-center gap-2">
                        {(['from', 'to'] as const).map((bound) => (
                            <span key={bound} className="flex gap-1">
                                <select
                                    aria-label={`${t(bound === 'from' ? 'Start' : 'End')} ${t('Month')}`}
                                    value={monthDay?.[`${bound}_month`] ?? ''}
                                    onChange={(e) => onMonthDay(`${bound}_month`, e.target.value)}
                                >
                                    <option value="">{t('Month')}</option>
                                    {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                                        <option key={m} value={m}>{m}</option>
                                    ))}
                                </select>
                                <select
                                    aria-label={`${t(bound === 'from' ? 'Start' : 'End')} ${t('Day')}`}
                                    value={monthDay?.[`${bound}_day`] ?? ''}
                                    onChange={(e) => onMonthDay(`${bound}_day`, e.target.value)}
                                >
                                    <option value="">{t('Day')}</option>
                                    {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => (
                                        <option key={d} value={d}>{d}</option>
                                    ))}
                                </select>
                                {bound === 'from' && <span>–</span>}
                            </span>
                        ))}
                    </span>
                );

            case 'select':
            case 'radio':
                return (
                    <select value={scalar} onChange={(e) => onValue(e.target.value)}>
                        <option value="">{t('Any')}</option>
                        {field.options.map((opt) => (
                            <option key={opt.id} value={opt.id}>{opt.caption}</option>
                        ))}
                    </select>
                );

            case 'checkbox':
                return field.options.map((opt) => (
                    <label key={opt.id}>
                        <input
                            type="checkbox"
                            checked={selected.includes(opt.id)}
                            onChange={() =>
                                onValue(selected.includes(opt.id) ? selected.filter((v) => v !== opt.id) : [...selected, opt.id])
                            }
                        />
                        {opt.caption}
                    </label>
                ));

            case 'date':
                return (
                    <span className="flex items-center gap-2">
                        <input type="date" value={range?.from ?? ''} onChange={(e) => onRange('from', e.target.value)} />
                        <span>–</span>
                        <input type="date" value={range?.to ?? ''} onChange={(e) => onRange('to', e.target.value)} />
                    </span>
                );

            case 'country_select':
                return (
                    <select value={scalar} onChange={(e) => onValue(e.target.value)}>
                        <option value="">{t('Any')}</option>
                        {field.countries?.map((c) => (
                            <option key={c.value} value={c.value}>{c.label}</option>
                        ))}
                    </select>
                );

            case 'region_select': {
                const groups = field.regions ?? [];
                const grouped = (groups[0]?.country ?? '') !== '';
                return (
                    <select value={scalar} onChange={(e) => onValue(e.target.value)}>
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
                    </select>
                );
            }

            default:
                return <input type="text" value={scalar} onChange={(e) => onValue(e.target.value)} />;
        }
    };

    return (
        <div>
            <label>{field.caption}</label>
            {render()}
        </div>
    );
}
