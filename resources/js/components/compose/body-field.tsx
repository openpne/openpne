import { MarkdownPreview } from '@/components/markdown-preview';
import { MarkdownToggle } from '@/components/markdown-toggle';
import { Field } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';

export type ComposeFormat = 'plain' | 'markdown';

interface BodyFieldProps {
    id: string;
    label: string; // pre-translated by the page
    value: string;
    onChange: (body: string) => void;
    error?: string;
    rows?: number;
    required?: boolean;
    /** undefined ⇔ op3 record: the page omitted `format` from its form; renders the OpenPNE 3 note. */
    format?: ComposeFormat;
    onFormatChange?: (format: ComposeFormat) => void;
}

/**
 * The single body-authoring block shared by the compose forms. `format === undefined` marks an op3
 * record — the page dropped the `format` field so the server preserves it — and shows a static note
 * instead of the Markdown toggle + live preview.
 */
export function BodyField({ id, label, value, onChange, error, rows, required, format, onFormatChange }: BodyFieldProps) {
    const t = useT();

    return (
        <>
            <Field label={label} htmlFor={id} error={error}>
                <Textarea id={id} required={required} rows={rows} value={value} onChange={(e) => onChange(e.target.value)} />
            </Field>

            {format === undefined ? (
                <p className="text-sm text-muted-foreground">{t('This entry keeps its OpenPNE 3 formatting.')}</p>
            ) : (
                <>
                    <MarkdownToggle checked={format === 'markdown'} onChange={(on) => onFormatChange?.(on ? 'markdown' : 'plain')} />
                    <MarkdownPreview body={value} enabled={format === 'markdown'} />
                </>
            )}
        </>
    );
}
