import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MemberRow, SearchCriteria, SearchFormField } from './types';

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

    const setField = (id: number, value: string | string[]) => setProfile((p) => ({ ...p, [id]: value }));
    const setRange = (id: number, key: 'from' | 'to', value: string) =>
        setDate((d) => ({ ...d, [id]: { ...d[id], [key]: value } }));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/m/member/search', { name, profile, date }, { preserveState: false });
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
                            onValue={(v) => setField(field.id, v)}
                            onRange={(k, v) => setRange(field.id, k, v)}
                        />
                    ))}

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
                                    <Link href={`/member/${member.id}`} className="flex items-center gap-3">
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
    onValue: (value: string | string[]) => void;
    onRange: (key: 'from' | 'to', value: string) => void;
}

function SearchField({ field, value, range, onValue, onRange }: SearchFieldProps) {
    const t = useT();
    const scalar = typeof value === 'string' ? value : '';
    const selected = Array.isArray(value) ? value : [];

    const render = () => {
        switch (field.formType) {
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
