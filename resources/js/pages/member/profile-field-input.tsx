import { useT } from '@/lib/i18n';
import type { ProfileFormField } from './types';

interface Props {
    field: ProfileFormField;
    /** Checkbox: a list of option ids; every other type: a scalar string. */
    value: string | string[];
    onChange: (next: string | string[]) => void;
    error?: string;
}

/**
 * Polymorphic profile field control, rendering the input that matches the field's form_type.
 * Option ids are strings throughout (a custom field's option id, a preset's choice key, or a
 * country/region code), so a single string comparison drives selection state.
 */
export function ProfileFieldInput({ field, value, onChange, error }: Props) {
    const t = useT();
    const id = `profile-${field.id}`;
    const scalar = typeof value === 'string' ? value : '';
    const selected = Array.isArray(value) ? value : [];

    const toggle = (optionId: string) =>
        onChange(selected.includes(optionId) ? selected.filter((v) => v !== optionId) : [...selected, optionId]);

    const renderInput = () => {
        switch (field.form_type) {
            case 'textarea':
                return <textarea id={id} rows={5} value={scalar} onChange={(e) => onChange(e.target.value)} />;

            case 'select':
                return (
                    <select id={id} value={scalar} onChange={(e) => onChange(e.target.value)}>
                        <option value="">{t('Please Select')}</option>
                        {field.options.map((opt) => (
                            <option key={opt.id} value={opt.id}>{opt.caption}</option>
                        ))}
                    </select>
                );

            case 'radio':
                return field.options.map((opt) => (
                    <label key={opt.id}>
                        <input type="radio" name={id} value={opt.id} checked={scalar === opt.id} onChange={() => onChange(opt.id)} />
                        {opt.caption}
                    </label>
                ));

            case 'checkbox':
                return field.options.map((opt) => (
                    <label key={opt.id}>
                        <input type="checkbox" value={opt.id} checked={selected.includes(opt.id)} onChange={() => toggle(opt.id)} />
                        {opt.caption}
                    </label>
                ));

            case 'date':
                return <input id={id} type="date" value={scalar} onChange={(e) => onChange(e.target.value)} />;

            case 'country_select':
                return (
                    <select id={id} value={scalar} onChange={(e) => onChange(e.target.value)}>
                        <option value="">{t('Please Select')}</option>
                        {field.countries?.map((c) => (
                            <option key={c.value} value={c.value}>{c.label}</option>
                        ))}
                    </select>
                );

            case 'region_select': {
                const groups = field.regions ?? [];
                const grouped = (groups[0]?.country ?? '') !== '';
                return (
                    <select id={id} value={scalar} onChange={(e) => onChange(e.target.value)}>
                        <option value="">{t('Please Select')}</option>
                        {grouped
                            ? groups.map((g) => (
                                <optgroup key={g.country} label={g.country}>
                                    {g.options.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </optgroup>
                            ))
                            : groups[0]?.options.map((opt) => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                    </select>
                );
            }

            case 'input':
            default:
                return <input id={id} type="text" value={scalar} onChange={(e) => onChange(e.target.value)} />;
        }
    };

    return (
        <div>
            <label htmlFor={id}>
                {field.caption}
                {field.is_required && <span aria-label="required">*</span>}
            </label>
            {renderInput()}
            {field.info && <p>{field.info}</p>}
            {error && <p role="alert">{error}</p>}
        </div>
    );
}
