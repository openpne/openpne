import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import type { ProfileFormField } from './types';

interface Props {
    field: ProfileFormField;
    /** Checkbox: a list of option ids; every other type: a scalar string. */
    value: string | string[];
    onChange: (next: string | string[]) => void;
    error?: string;
}

// Native radio matching the Checkbox primitive's token styling (there is no separate Radio primitive).
const radioClass =
    'size-4 shrink-0 accent-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';

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

    // Radio/checkbox render a set of options, so they are a fieldset group rather than a single Field control.
    if (field.form_type === 'radio' || field.form_type === 'checkbox') {
        const isRadio = field.form_type === 'radio';
        const errorId = error ? `${id}-error` : undefined;
        return (
            <fieldset className="space-y-2" aria-invalid={error ? true : undefined} aria-describedby={errorId}>
                <legend className="text-sm font-medium text-foreground">
                    {field.caption}
                    {field.is_required && <span className="text-destructive"> *</span>}
                </legend>
                <div className="space-y-1.5">
                    {field.options.map((opt) => (
                        <label key={opt.id} className="flex items-center gap-2 text-sm text-foreground">
                            {isRadio ? (
                                <input type="radio" name={id} value={opt.id} checked={scalar === opt.id} onChange={() => onChange(opt.id)} className={radioClass} />
                            ) : (
                                <Checkbox value={opt.id} checked={selected.includes(opt.id)} onChange={() => toggle(opt.id)} />
                            )}
                            {opt.caption}
                        </label>
                    ))}
                </div>
                {field.info && !error && <p className="text-xs text-muted-foreground">{field.info}</p>}
                {error && (
                    <p id={errorId} role="alert" className="text-xs text-destructive">
                        {error}
                    </p>
                )}
            </fieldset>
        );
    }

    const control = (() => {
        switch (field.form_type) {
            case 'textarea':
                return <Textarea id={id} rows={5} value={scalar} onChange={(e) => onChange(e.target.value)} />;

            case 'select':
                return (
                    <Select id={id} value={scalar} onChange={(e) => onChange(e.target.value)}>
                        <option value="">{t('Please Select')}</option>
                        {field.options.map((opt) => (
                            <option key={opt.id} value={opt.id}>{opt.caption}</option>
                        ))}
                    </Select>
                );

            case 'date':
                return <Input id={id} type="date" value={scalar} onChange={(e) => onChange(e.target.value)} />;

            case 'country_select':
                return (
                    <Select id={id} value={scalar} onChange={(e) => onChange(e.target.value)}>
                        <option value="">{t('Please Select')}</option>
                        {field.countries?.map((c) => (
                            <option key={c.value} value={c.value}>{c.label}</option>
                        ))}
                    </Select>
                );

            case 'region_select': {
                const groups = field.regions ?? [];
                const grouped = (groups[0]?.country ?? '') !== '';
                return (
                    <Select id={id} value={scalar} onChange={(e) => onChange(e.target.value)}>
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
                    </Select>
                );
            }

            case 'input':
            default:
                return <Input id={id} type="text" value={scalar} onChange={(e) => onChange(e.target.value)} />;
        }
    })();

    return (
        <Field label={field.caption} htmlFor={id} required={field.is_required} help={field.info || undefined} error={error}>
            {control}
        </Field>
    );
}
