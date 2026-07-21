import { lazy, Suspense, useState } from 'react';
import { MarkdownPreview } from '@/components/markdown-preview';
import { MarkdownToggle } from '@/components/markdown-toggle';
import { Field } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { initialEditorMode, type ComposeEditorPreference, type EditorMode, type RecordFormat } from './editor-mode';
import { saveComposeEditor } from './save-compose-editor';

export type ComposeFormat = 'plain' | 'markdown';

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
    /** The member's saved editor choice; picks the initial mode for a markdown/create body. */
    editorPreference: ComposeEditorPreference;
    /** The stored record format (undefined = create); read once to pick the initial mode. */
    recordFormat?: RecordFormat;
}

/**
 * The single body-authoring block shared by the compose forms. It offers two editors over the same
 * `format=markdown` body: a WYSIWYG "rich" editor and the "raw" textarea + Markdown toggle + live
 * preview. The chosen editor is remembered per member (PreferenceKey::ComposeEditor); switching also
 * saves the new choice. A plain record always opens raw and an op3 record (`format === undefined`)
 * gets no editor at all — see editor-mode.ts and docs/internals/body-text.md.
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
    // Resolution runs once (useState initializer) and NEVER writes the preference — a mount is not a
    // member choice; only an explicit switch persists.
    const [mode, setMode] = useState<EditorMode>(() => initialEditorMode(editorPreference, recordFormat));
    // Bumped on every raw→rich switch to remount RichTextEditor, so it re-parses the latest textarea
    // text (its initialMarkdown is captured once, at mount).
    const [switchCount, setSwitchCount] = useState(0);

    const errorId = error ? `${id}-error` : undefined;

    // op3: unchanged. `format === undefined` is the op3 signal (the page omitted the field so the
    // server preserves the stored format); show the static note, no toggle, no editor switch.
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

    if (mode === 'rich') {
        // In rich mode `format` is always 'markdown' (the entering transition set it; nothing here
        // flips it back). `initialMarkdown` is the form value AT MOUNT and the switch never rewrites
        // the form value, so an unedited body submits unchanged — mount/parse fires no onChange
        // (dirty-contract.test.ts); the form body only changes once the member edits. (Transport
        // caveat shared with raw mode: multipart encoding normalizes LF to CRLF — body-text.md.)
        // Accepted edge: raw→rich→raw with zero edits leaves format=markdown (the toggle shows it
        // checked, and the member can uncheck to revert).
        //
        // a11y: Field clone-injects id/aria-* into its direct child, which cannot reach the
        // contenteditable through <Suspense>. So we render the label + error ourselves and forward
        // the hyphen-named DOM attributes straight to RichTextEditor, which stamps them on the editable.
        return (
            <div className="space-y-2">
                <Label htmlFor={id}>{label}</Label>
                <Suspense
                    fallback={
                        <div className="flex min-h-24 w-full items-center rounded-field border border-field-border bg-field px-3 py-2 text-sm text-muted-foreground">
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
                        aria-required={required ? 'true' : undefined}
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={errorId}
                    />
                </Suspense>
                {error && (
                    <p id={errorId} role="alert" className="text-xs text-destructive">
                        {error}
                    </p>
                )}
                <button
                    type="button"
                    className="text-sm text-link hover:underline"
                    onClick={() => {
                        setMode('raw');
                        // format stays 'markdown' (no onFormatChange) — the raw toggle arrives checked.
                        saveComposeEditor('markdown');
                    }}
                >
                    {t('Edit as Markdown')}
                </button>
            </div>
        );
    }

    // raw mode: textarea + Markdown toggle + live preview, plus a link into the rich editor.
    return (
        <>
            <Field label={label} htmlFor={id} error={error}>
                <Textarea id={id} required={required} rows={rows} value={value} onChange={(e) => onChange(e.target.value)} />
            </Field>

            <MarkdownToggle checked={format === 'markdown'} onChange={(on) => onFormatChange?.(on ? 'markdown' : 'plain')} />
            <MarkdownPreview body={value} enabled={format === 'markdown'} />

            <button
                type="button"
                className="text-sm text-link hover:underline"
                onClick={() => {
                    onFormatChange?.('markdown');
                    setMode('rich');
                    setSwitchCount((n) => n + 1);
                    saveComposeEditor('rich');
                }}
            >
                {t('Switch to the rich text editor')}
            </button>
        </>
    );
}
