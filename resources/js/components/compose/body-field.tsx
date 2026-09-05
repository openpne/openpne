import { lazy, Suspense, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { MarkdownPreview } from '@/components/markdown-preview';
import { Field } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { composeEditorRowsMinHeight } from './editor-rows';
import {
    applyInputMethod,
    initialEditorMode,
    inputMethodFor,
    needsFormatConfirm,
    type ComposeEditorPreference,
    type ComposeFormat,
    type EditorMode,
    type InputMethod,
    type RecordFormat,
} from './editor-mode';
import { InputMethodBadge, InputMethodMenu } from './input-method-menu';
import { saveComposeEditor } from './save-compose-editor';

// Module scope + lazy: tiptap and its extensions ship in a separate chunk, loaded the first time any
// compose form opens the rich editor and shared across all of them.
const RichTextEditor = lazy(() => import('./rich-text-editor'));

/**
 * Wider than a plain field's gap because the input-method trigger's touch target overhangs the row
 * by 12px, and anything less lets the control below take taps meant for the trigger. Both branches
 * share it, so switching method moves nothing.
 */
const FIELD_GAP = 'space-y-3';

interface BodyFieldProps {
    id: string;
    label: string; // pre-translated by the page
    value: string;
    onChange: (body: string) => void;
    error?: string;
    rows?: number;
    required?: boolean;
    /**
     * undefined ⇔ op3 record: the page omitted `format` from its form; renders the OpenPNE 3 note.
     */
    format?: ComposeFormat;
    onFormatChange?: (format: ComposeFormat) => void;
    editorPreference: ComposeEditorPreference;
    /** Read once, to pick the initial state: a later change has no effect. */
    recordFormat?: RecordFormat;
}

/**
 * The body-authoring block shared by the compose forms (docs/internals/body-text.md, "Authoring:
 * the input method").
 */
export function BodyField({
    id,
    label,
    value,
    onChange,
    error,
    rows,
    required,
    format,
    onFormatChange,
    editorPreference,
    recordFormat,
}: BodyFieldProps) {
    const t = useT();
    const confirm = useConfirm();
    // Resolution runs once (useState initializer) and NEVER writes the preference — a mount is not a
    // member choice; only an explicit pick persists.
    const [mode, setMode] = useState<EditorMode>(() => initialEditorMode(editorPreference, recordFormat));
    // Bumped whenever the rich editor is (re-)entered, to remount it so it re-parses the latest
    // textarea text (its initialMarkdown is captured once, at mount).
    const [switchCount, setSwitchCount] = useState(0);

    const errorId = error ? `${id}-error` : undefined;

    // `format === undefined` is the op3 signal: the page omitted the field, so the server preserves
    // the stored format.
    if (format === undefined) {
        return (
            <>
                <Field label={label} htmlFor={id} error={error}>
                    <Textarea id={id} required={required} rows={rows} value={value} onChange={(e) => onChange(e.target.value)} />
                </Field>
                <p className="text-sm text-muted-foreground">{t('This entry keeps its OpenPNE 3 formatting.')}</p>
            </>
        );
    }

    const method = inputMethodFor(mode, format);

    const selectMethod = async (next: InputMethod) => {
        if (next === method) {
            return;
        }
        const applied = applyInputMethod(next);
        if (
            needsFormatConfirm(recordFormat, format, next) &&
            !(await confirm({
                title: t('Change the input method?'),
                description:
                    applied.format === 'markdown'
                        ? t('Symbols like # and * in this entry will start being treated as formatting.')
                        : t('Formatting symbols in this entry will be shown as characters, exactly as typed.'),
            }))
        ) {
            return;
        }
        // The switch never rewrites the form value, so an unedited body still submits unchanged; the
        // conversion is in `format` alone.
        if (applied.format !== format) {
            onFormatChange?.(applied.format);
        }
        if (applied.mode === 'rich' && mode !== 'rich') {
            setSwitchCount((n) => n + 1);
        }
        setMode(applied.mode);
        saveComposeEditor(next);
    };

    // Field's clone-injection cannot reach the contenteditable through <Suspense>, so the label, the
    // aria-* wiring and the error are rendered by hand in both branches.
    const header = (
        // The trigger's touch target overhangs this fixed-height row; FIELD_GAP below pays for it.
        <div className="flex h-5 items-center gap-1">
            <Label htmlFor={id}>{label}</Label>
            <InputMethodMenu value={method} onSelect={selectMethod} />
            <InputMethodBadge method={method} />
        </div>
    );

    const errorNode = error ? (
        <p id={errorId} role="alert" className="text-xs text-destructive">
            {error}
        </p>
    ) : null;

    if (mode === 'rich') {
        return (
            <div className={FIELD_GAP}>
                {header}
                <Suspense
                    fallback={
                        // Same height the editable will open at, off the same line-height, so the lazy
                        // chunk lands without the field jumping.
                        <div
                            style={rows ? { minHeight: composeEditorRowsMinHeight(rows) } : undefined}
                            className="flex min-h-24 w-full items-center rounded-field border border-field-border bg-field px-3 py-2 text-base text-muted-foreground md:text-sm"
                        >
                            {t('Loading editor…')}
                        </div>
                    }
                >
                    <RichTextEditor
                        key={switchCount}
                        initialMarkdown={value}
                        onChange={onChange}
                        label={label}
                        id={id}
                        rows={rows}
                        aria-required={required ? 'true' : undefined}
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={errorId}
                    />
                </Suspense>
                {errorNode}
            </div>
        );
    }

    return (
        <div className={FIELD_GAP}>
            {header}
            <Textarea
                id={id}
                required={required}
                rows={rows}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={errorId}
            />
            {errorNode}
            <MarkdownPreview body={value} enabled={format === 'markdown'} />
        </div>
    );
}
