import { lazy, Suspense, useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { MarkdownPreview } from '@/components/markdown-preview';
import { Field, FRAME_INSET } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
// `import type`, not a named type import: a value-shaped import statement would pull
// editor-extensions (and with it tiptap) into this chunk, defeating the lazy RichTextEditor split.
import type { ComposeLayout } from './editor-extensions';
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
 * The row layout's editing surface: below sm it is the only part that does NOT take the frame inset as
 * padding on its box — the box runs the full width and re-spends the inset on its own text instead, so
 * the tappable surface reaches both screen edges while the text lines up with the label above it. A
 * taller min-h as well: a full-width surface three lines tall reads as a scrap of a form rather than a
 * page to write on. From sm up it is the ordinary boxed field again.
 */
const ROW_SURFACE = 'min-h-40 px-(--frame-inset) py-3 sm:min-h-24 sm:px-3 sm:py-2';

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
    /**
     * `'row'` when the host form is a `Panel bleed="full"` body: below sm the editing surface runs to
     * both screen edges and the label/error keep the frame inset. Opt-in per page — the message and
     * comment forms, and the compose pages not converted yet, stay on the boxed `'stack'` layout.
     */
    layout?: ComposeLayout;
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
    layout = 'stack',
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

    // In the row layout this block sits in a `Panel bleed="full"` body, which pays no horizontal
    // padding — so each part pays the frame inset itself, EXCEPT the editing surface, which runs to
    // both screen edges and re-spends the inset internally (see ROW_SURFACE). All of it collapses from
    // sm up, where the panel pads again.
    const inset = layout === 'row' ? FRAME_INSET : undefined;

    const errorNode = error ? (
        <p id={errorId} role="alert" className={cn(inset, 'text-xs text-destructive')}>
            {error}
        </p>
    ) : null;

    // op3: `format === undefined` is the op3 signal (the page omitted the field so the server
    // preserves the stored format); show the static note, no menu, no editor choice. The row layout
    // needs its own return: the stack markup is a Fragment whose boxed Textarea would sit at x=0 in a
    // panel that pays no side padding.
    if (format === undefined) {
        const note = t('This entry keeps its OpenPNE 3 formatting.');
        const textarea = (
            <Textarea
                id={id}
                variant={layout === 'row' ? 'bare' : 'field'}
                required={required}
                rows={rows}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                // Only in the row layout: there is no Field to clone-inject these. In the stack layout
                // Field owns them, and passing them here as well would duplicate the describedby token.
                aria-invalid={layout === 'row' && error ? true : undefined}
                aria-describedby={layout === 'row' ? errorId : undefined}
                className={layout === 'row' ? ROW_SURFACE : undefined}
            />
        );

        if (layout === 'row') {
            return (
                <div className="space-y-2">
                    <div className={inset}>
                        <Label htmlFor={id}>{label}</Label>
                    </div>
                    {textarea}
                    {errorNode}
                    <p className={cn(inset, 'text-sm text-muted-foreground')}>{note}</p>
                </div>
            );
        }

        return (
            <>
                <Field label={label} htmlFor={id} error={error}>
                    {textarea}
                </Field>
                <p className="text-sm text-muted-foreground">{note}</p>
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
        <div className={cn(inset, 'flex items-center justify-between gap-2')}>
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-2">
                <InputMethodBadge method={method} />
                <InputMethodMenu value={method} onSelect={selectMethod} />
            </div>
        </div>
    );

    if (mode === 'rich') {
        return (
            <div className="space-y-2">
                {header}
                <Suspense
                    fallback={
                        <div
                            className={cn(
                                'flex w-full items-center text-sm text-muted-foreground',
                                layout === 'row'
                                    ? `${ROW_SURFACE} sm:rounded-field sm:border sm:border-field-border sm:bg-field`
                                    : 'min-h-24 rounded-field border border-field-border bg-field px-3 py-2',
                            )}
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
                        layout={layout}
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
                variant={layout === 'row' ? 'bare' : 'field'}
                required={required}
                rows={rows}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={errorId}
                className={layout === 'row' ? ROW_SURFACE : undefined}
            />
            {errorNode}
            <MarkdownPreview body={value} enabled={format === 'markdown'} className={inset} />
        </div>
    );
}
