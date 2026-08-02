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
    /** The member's saved input method; picks the initial state for a markdown/create body. */
    editorPreference: ComposeEditorPreference;
    /** The stored record format (undefined = create); read once to pick the initial state. */
    recordFormat?: RecordFormat;
}

/**
 * The single body-authoring block shared by the compose forms. One member-facing choice — the input
 * method behind the "…" on the label row — selects between the WYSIWYG editor, a Markdown textarea
 * (with live preview), and an unformatted textarea; internally that is `mode × format`, which the UI
 * never exposes. The choice is remembered per member (PreferenceKey::ComposeEditor) and only an
 * explicit pick persists. A plain record always opens unformatted and an op3 record
 * (`format === undefined`) gets no control at all — see editor-mode.ts and docs/internals/body-text.md.
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

    // op3: unchanged. `format === undefined` is the op3 signal (the page omitted the field so the
    // server preserves the stored format); show the static note, no menu, no editor choice.
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
        // The switch never rewrites the form value, so an unedited body still submits unchanged — the
        // conversion is in `format` alone (dirty-contract.test.ts covers the parse side).
        if (applied.format !== format) {
            onFormatChange?.(applied.format);
        }
        if (applied.mode === 'rich' && mode !== 'rich') {
            setSwitchCount((n) => n + 1);
        }
        setMode(applied.mode);
        saveComposeEditor(next);
    };

    // One skeleton for both editors: the label row (and therefore the input-method control) sits in
    // the same place whichever is showing, so switching never moves it. Field's clone-injection is not
    // usable here — it cannot reach the contenteditable through <Suspense> — so the label, the aria-*
    // wiring, and the error are rendered by hand for both branches alike.
    const header = (
        // The trigger's touch target is taller than the label text, so let it overhang the row
        // instead of setting the row's height: otherwise this field's label sits further from its
        // control than every other field's does.
        <div className="flex h-5 items-center justify-between gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-2">
                <InputMethodBadge method={method} />
                <InputMethodMenu value={method} onSelect={selectMethod} />
            </div>
        </div>
    );

    const errorNode = error ? (
        <p id={errorId} role="alert" className="text-xs text-destructive">
            {error}
        </p>
    ) : null;

    if (mode === 'rich') {
        return (
            <div className="space-y-2">
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
        <div className="space-y-2">
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
