import { useEffect, useId, useRef, useState, type ComponentType, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { EditorContent, useEditor } from '@tiptap/react';
import type { Editor } from '@tiptap/core';
import {
    Bold,
    Code,
    Columns3,
    Heading2,
    Heading3,
    Heading4,
    Italic,
    Link2,
    List,
    ListOrdered,
    Minus,
    MoreHorizontal,
    Quote,
    Rows3,
    SquareCode,
    Strikethrough,
    Table as TableIcon,
    Trash2,
} from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { composeEditorAttributes, createComposeEditorOptions } from '@/components/compose/editor-extensions';
import { composeEditorRowsStyle } from '@/components/compose/editor-rows';

type RichTextEditorProps = {
    initialMarkdown: string;
    onChange: (md: string) => void;
    /** Accessible name; forwarded as aria-label onto the editable and reused for the toolbar. */
    label: string;
    id?: string;
    /** Opening height in line boxes, read the same way `<textarea rows>` reads it. */
    rows?: number;
    // Hyphen-named DOM attributes exactly as components/ui/field.tsx clone-injects them.
    'aria-required'?: 'true';
    'aria-invalid'?: 'true';
    'aria-describedby'?: string;
};

// Below Tailwind's md (768px): the row carries the compact button set and demotes the rest into "More".
const COMPACT_QUERY = '(max-width: 767.98px)';

// size-9 (36px) resting; pointer-coarse bumps every target to 44px (Apple HIG / Material touch floor).
const TOOLBAR_BUTTON_CLASS =
    'inline-flex size-9 pointer-coarse:size-11 items-center justify-center rounded-field text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40 aria-pressed:bg-accent aria-pressed:text-accent-foreground';

/** Track a media query so the compact button set engages below md (matches the CSS breakpoint). */
function useIsCompact(): boolean {
    const [compact, setCompact] = useState(
        () => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia(COMPACT_QUERY).matches,
    );

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return;
        }
        const mql = window.matchMedia(COMPACT_QUERY);
        const onChange = () => setCompact(mql.matches);
        onChange();
        mql.addEventListener('change', onChange);
        return () => mql.removeEventListener('change', onChange);
    }, []);

    return compact;
}

type ToolbarAction = {
    label: string;
    icon: ComponentType<{ className?: string }>;
    active: boolean;
    run: () => void;
};

/**
 * Single source for every formatting command, so the full row and the compact row's "More" menu can't
 * drift and every chain carries `.scrollIntoView()` (ProseMirror otherwise scrolls inconsistently by
 * command — the toolbar's onMouseDown keeps the editor focused, so focus() early-returns and never
 * scrolls on its own). Recomputed each render; `active` reads the live selection.
 */
function useToolbarActions(editor: Editor) {
    return {
        bold: { label: 'Bold', icon: Bold, active: editor.isActive('bold'), run: () => editor.chain().focus().toggleBold().scrollIntoView().run() },
        italic: { label: 'Italic', icon: Italic, active: editor.isActive('italic'), run: () => editor.chain().focus().toggleItalic().scrollIntoView().run() },
        strike: {
            label: 'Strikethrough',
            icon: Strikethrough,
            active: editor.isActive('strike'),
            run: () => editor.chain().focus().toggleStrike().scrollIntoView().run(),
        },
        code: { label: 'Inline code', icon: Code, active: editor.isActive('code'), run: () => editor.chain().focus().toggleCode().scrollIntoView().run() },
        h2: {
            label: 'Heading 2',
            icon: Heading2,
            active: editor.isActive('heading', { level: 2 }),
            run: () => editor.chain().focus().toggleHeading({ level: 2 }).scrollIntoView().run(),
        },
        h3: {
            label: 'Heading 3',
            icon: Heading3,
            active: editor.isActive('heading', { level: 3 }),
            run: () => editor.chain().focus().toggleHeading({ level: 3 }).scrollIntoView().run(),
        },
        h4: {
            label: 'Heading 4',
            icon: Heading4,
            active: editor.isActive('heading', { level: 4 }),
            run: () => editor.chain().focus().toggleHeading({ level: 4 }).scrollIntoView().run(),
        },
        bulletList: {
            label: 'Bullet list',
            icon: List,
            active: editor.isActive('bulletList'),
            run: () => editor.chain().focus().toggleBulletList().scrollIntoView().run(),
        },
        orderedList: {
            label: 'Numbered list',
            icon: ListOrdered,
            active: editor.isActive('orderedList'),
            run: () => editor.chain().focus().toggleOrderedList().scrollIntoView().run(),
        },
        quote: {
            label: 'Quote',
            icon: Quote,
            active: editor.isActive('blockquote'),
            run: () => editor.chain().focus().toggleBlockquote().scrollIntoView().run(),
        },
        codeBlock: {
            label: 'Code block',
            icon: SquareCode,
            active: editor.isActive('codeBlock'),
            run: () => editor.chain().focus().toggleCodeBlock().scrollIntoView().run(),
        },
        hr: { label: 'Horizontal rule', icon: Minus, active: false, run: () => editor.chain().focus().setHorizontalRule().scrollIntoView().run() },
    };
}

/** onMouseDown preventDefault keeps the editor selection (and the mobile keyboard) while clicking. */
function ToolbarButton({
    label,
    pressed,
    disabled,
    onClick,
    children,
}: {
    label: string;
    pressed?: boolean;
    disabled?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            aria-pressed={pressed}
            title={label}
            disabled={disabled}
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            className={TOOLBAR_BUTTON_CLASS}
        >
            {children}
        </button>
    );
}

/** Icon-only toolbar button bound to a {@link ToolbarAction}. */
function ActionButton({ action }: { action: ToolbarAction }) {
    const t = useT();
    const Icon = action.icon;
    return (
        <ToolbarButton label={t(action.label)} pressed={action.active} onClick={action.run}>
            <Icon className="size-4" />
        </ToolbarButton>
    );
}

/** Text + icon row inside the "More" menu — the label doubles as the icon's meaning on touch. */
function MoreItem({
    label,
    icon: Icon,
    pressed,
    onSelect,
}: {
    label: string;
    icon: ComponentType<{ className?: string }>;
    pressed?: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            onMouseDown={(event) => event.preventDefault()}
            onClick={onSelect}
            className="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm text-foreground transition hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-pressed:bg-accent aria-pressed:text-accent-foreground"
        >
            <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            <span className="flex-1 text-left">{label}</span>
        </button>
    );
}

/**
 * Overflow menu for the formatting + table commands the compact row demotes. A plain (non-Radix)
 * popover on purpose: Radix menus pull DOM focus into the menu for arrow-key navigation, which drops
 * the editor selection. Here the items preventDefault on mousedown, so picking one never moves focus
 * and the command applies to the live selection.
 *
 * Opening dismisses the soft keyboard on purpose. The panel is taller than the band left above an
 * open keyboard, and its max height is layout-viewport based — the keyboard shrinks only the visual
 * viewport — so the last items would sit behind the keyboard with no way to scroll them out. The
 * selection lives in the editor state rather than in DOM focus, so each item's `chain().focus()`
 * brings back both the caret and the keyboard.
 *
 * That dismissal is also what lets the panel sit as a bottom sheet on the layout viewport: with no
 * keyboard the layout viewport is what the member sees, so `bottom-0` is the real bottom of the
 * screen and the whole list is reachable however short the page is. Anchoring it to the trigger
 * instead put the tail below the fold of a page with nothing to scroll.
 *
 * The sheet is portaled to <body> because the toolbar's `z-10` opens a stacking context: rendered in
 * place, no z-index could lift it over the app shell's fixed bottom bar, which swallowed the taps on
 * the last item.
 */
function MoreMenu({ editor }: { editor: Editor }) {
    const t = useT();
    const actions = useToolbarActions(editor);
    const [open, setOpen] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const panelId = useId();
    const inTable = editor.isActive('table');

    useEffect(() => {
        if (!open) {
            return;
        }
        // The sheet is portaled, so "inside" spans both roots: the trigger's container and the sheet.
        // Everything else — including the backdrop — is outside and dismisses.
        const inside = (target: EventTarget | null) =>
            Boolean(containerRef.current?.contains(target as Node) || panelRef.current?.contains(target as Node));
        const onPointerDown = (event: PointerEvent) => {
            if (!inside(event.target)) {
                setOpen(false);
            }
        };
        // Close if focus reaches anything outside the sheet — a command handing it back to the
        // editable, say — so the popover never lingers over an external control. Tab alone cannot
        // get there; it cycles inside (see onKeyDown).
        const onFocusIn = (event: FocusEvent) => {
            if (!inside(event.target)) {
                setOpen(false);
            }
        };
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                triggerRef.current?.focus();
                return;
            }
            if (event.key !== 'Tab' || !panelRef.current) {
                return;
            }
            // Tab cycles inside the sheet. The portal put it at the end of <body>, so leaving it by
            // Tab would drop focus to nothing and then wrap to the top of the document rather than
            // reach the trigger it belongs to. Escape is the way out; the commands' own
            // `chain().focus()` still hands focus to the editable.
            const items = panelRef.current.querySelectorAll<HTMLElement>('button');
            const first = items[0];
            const last = items[items.length - 1];
            if (!first || !last) {
                return;
            }
            const leavingBackwards = event.shiftKey && (document.activeElement === first || document.activeElement === panelRef.current);
            const leavingForwards = !event.shiftKey && document.activeElement === last;
            if (leavingBackwards || leavingForwards) {
                event.preventDefault();
                (leavingBackwards ? last : first).focus();
            }
        };
        document.addEventListener('pointerdown', onPointerDown, true);
        document.addEventListener('focusin', onFocusIn);
        document.addEventListener('keydown', onKeyDown);
        // The portal puts the sheet at the end of <body>, so it no longer follows the trigger in Tab
        // order: enter it here instead. Nothing is lost — opening already blurred the editor, and the
        // selection each item applies to lives in the editor state.
        panelRef.current?.focus();
        return () => {
            document.removeEventListener('pointerdown', onPointerDown, true);
            document.removeEventListener('focusin', onFocusIn);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    const select = (run: () => void) => {
        run();
        setOpen(false);
    };

    return (
        <div ref={containerRef}>
            <button
                ref={triggerRef}
                type="button"
                aria-label={t('More formatting')}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-controls={open ? panelId : undefined}
                title={t('More formatting')}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => {
                    if (!open) {
                        editor.commands.blur();
                    }
                    setOpen((value) => !value);
                }}
                className={cn(TOOLBAR_BUTTON_CLASS, open && 'bg-accent text-accent-foreground')}
            >
                <MoreHorizontal className="size-4" />
            </button>
            {open &&
                createPortal(
                    <>
                        {/* Catches the tap that dismisses the sheet so it cannot also reach a link or
                            the bottom bar underneath. Deliberately undimmed: this list formats the
                            selection, and scrimming the text being formatted reads as hiding it. */}
                        <div aria-hidden className="fixed inset-0 z-50" />
                        <div
                            ref={panelRef}
                            id={panelId}
                            // A named dialog, not a menu: the items are toggles rather than
                            // arrow-navigated menuitems. Tab cycles within it, but aria-modal stays
                            // off — a command may hand focus straight back to the editable.
                            role="dialog"
                            aria-label={t('More formatting')}
                            tabIndex={-1}
                            data-testid="compose-more-panel"
                            // Two columns: the sheet covers whatever the member is formatting, so
                            // halving its rows is halving how much of the selection it hides. The
                            // order below is by row pair — the grid flows left→right, so it is the
                            // pairing, not a separator, that groups related commands.
                            className="fixed inset-x-0 bottom-0 z-50 grid max-h-[70dvh] grid-cols-2 gap-x-1 overflow-y-auto rounded-t-xl border-t border-border bg-card p-1 pb-[calc(0.25rem+env(safe-area-inset-bottom))] shadow-lg outline-none"
                        >
                            <MoreItem label={t('Italic')} icon={Italic} pressed={actions.italic.active} onSelect={() => select(actions.italic.run)} />
                            <MoreItem
                                label={t('Strikethrough')}
                                icon={Strikethrough}
                                pressed={actions.strike.active}
                                onSelect={() => select(actions.strike.run)}
                            />
                            <MoreItem label={t('Heading 3')} icon={Heading3} pressed={actions.h3.active} onSelect={() => select(actions.h3.run)} />
                            <MoreItem label={t('Heading 4')} icon={Heading4} pressed={actions.h4.active} onSelect={() => select(actions.h4.run)} />
                            <MoreItem
                                label={t('Numbered list')}
                                icon={ListOrdered}
                                pressed={actions.orderedList.active}
                                onSelect={() => select(actions.orderedList.run)}
                            />
                            <MoreItem label={t('Quote')} icon={Quote} pressed={actions.quote.active} onSelect={() => select(actions.quote.run)} />
                            <MoreItem label={t('Inline code')} icon={Code} pressed={actions.code.active} onSelect={() => select(actions.code.run)} />
                            <MoreItem
                                label={t('Code block')}
                                icon={SquareCode}
                                pressed={actions.codeBlock.active}
                                onSelect={() => select(actions.codeBlock.run)}
                            />
                            <MoreItem label={t('Horizontal rule')} icon={Minus} onSelect={() => select(actions.hr.run)} />
                            <MoreItem
                                label={t('Insert table')}
                                icon={TableIcon}
                                onSelect={() =>
                                    select(() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).scrollIntoView().run())
                                }
                            />
                            {/* Cell commands only exist for a caret inside a table; on a phone list they would
                                otherwise be five permanently greyed rows the member has to read past. */}
                            {inTable && (
                                <>
                                    <div role="separator" className="col-span-2 my-1 h-px bg-border" />
                                    <MoreItem
                                        label={t('Add row')}
                                        icon={Rows3}
                                        onSelect={() => select(() => editor.chain().focus().addRowAfter().scrollIntoView().run())}
                                    />
                                    <MoreItem
                                        label={t('Delete row')}
                                        icon={Rows3}
                                        onSelect={() => select(() => editor.chain().focus().deleteRow().scrollIntoView().run())}
                                    />
                                    <MoreItem
                                        label={t('Add column')}
                                        icon={Columns3}
                                        onSelect={() => select(() => editor.chain().focus().addColumnAfter().scrollIntoView().run())}
                                    />
                                    <MoreItem
                                        label={t('Delete column')}
                                        icon={Columns3}
                                        onSelect={() => select(() => editor.chain().focus().deleteColumn().scrollIntoView().run())}
                                    />
                                    <MoreItem
                                        label={t('Delete table')}
                                        icon={Trash2}
                                        onSelect={() => select(() => editor.chain().focus().deleteTable().scrollIntoView().run())}
                                    />
                                </>
                            )}
                        </div>
                    </>,
                    document.body,
                )}
        </div>
    );
}

/** Small dialog to set or clear the link on the current selection; only http/https is accepted. */
function LinkDialog({ editor }: { editor: Editor }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const [url, setUrl] = useState('');
    const [error, setError] = useState('');
    const urlId = useId();
    const errorId = `${urlId}-error`;

    const active = editor.isActive('link');

    function openDialog() {
        setUrl((editor.getAttributes('link').href as string | undefined) ?? '');
        setError('');
        setOpen(true);
    }

    function submit() {
        const trimmed = url.trim();
        if (trimmed === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().scrollIntoView().run();
            setOpen(false);
            return;
        }
        if (!/^https?:\/\//i.test(trimmed)) {
            setError(t('Enter an http:// or https:// address.'));
            return;
        }
        editor.chain().focus().extendMarkRange('link').setLink({ href: trimmed }).scrollIntoView().run();
        setOpen(false);
    }

    function remove() {
        editor.chain().focus().extendMarkRange('link').unsetLink().scrollIntoView().run();
        setOpen(false);
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <ToolbarButton label={t('Link')} pressed={active} onClick={openDialog}>
                <Link2 className="size-4" />
            </ToolbarButton>
            <DialogContent
                closeLabel={t('Close')}
                // Hand focus back to the editable on every close path (Apply / × / ESC / overlay-tap).
                // Radix would otherwise return it to the trigger, undoing the `.focus()` the link
                // command chain just ran and leaving the caret nowhere to keep typing.
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    editor.commands.focus();
                }}
            >
                <DialogTitle className="text-base font-semibold">{t('Link')}</DialogTitle>
                <form
                    className="mt-4 space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        // React propagates events through the React tree even across the dialog
                        // portal — without this, the submit bubbles into the host compose <form>
                        // and posts the whole entry.
                        event.stopPropagation();
                        submit();
                    }}
                >
                    <div className="space-y-1.5">
                        <label htmlFor={urlId} className="text-sm font-medium text-foreground">
                            {t('Link URL')}
                        </label>
                        <Input
                            id={urlId}
                            type="url"
                            inputMode="url"
                            placeholder="https://"
                            value={url}
                            autoFocus
                            aria-invalid={error ? true : undefined}
                            aria-describedby={error ? errorId : undefined}
                            onChange={(event) => setUrl(event.target.value)}
                        />
                        {error && (
                            <p id={errorId} role="alert" className="text-xs text-destructive">
                                {error}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" size="sm">
                            {t('Apply')}
                        </Button>
                        {active && (
                            <Button type="button" variant="outline" size="sm" onClick={remove}>
                                {t('Remove link')}
                            </Button>
                        )}
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Insert/edit table via a dropdown; row/column actions disable outside a table. md+ only. */
function TableMenu({ editor }: { editor: Editor }) {
    const t = useT();
    const inTable = editor.isActive('table');

    return (
        <DropdownMenu>
            <DropdownMenuTrigger aria-label={t('Table')} title={t('Table')} className={TOOLBAR_BUTTON_CLASS}>
                <TableIcon className="size-4" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuItem
                    onSelect={() =>
                        editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).scrollIntoView().run()
                    }
                >
                    <TableIcon className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Insert table')}</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().addRowAfter().scrollIntoView().run()}>
                    <Rows3 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Add row')}</span>
                </DropdownMenuItem>
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().deleteRow().scrollIntoView().run()}>
                    <Rows3 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Delete row')}</span>
                </DropdownMenuItem>
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().addColumnAfter().scrollIntoView().run()}>
                    <Columns3 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Add column')}</span>
                </DropdownMenuItem>
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().deleteColumn().scrollIntoView().run()}>
                    <Columns3 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Delete column')}</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().deleteTable().scrollIntoView().run()}>
                    <Trash2 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Delete table')}</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * The formatting row, sticky at the top of the form column at every width (below the persistent TopNav
 * via --modern-top-offset, which is 0 at lg) with an opaque background so the body scrolls under it.
 * The host Panel opts out of overflow clipping (Panel overflow="visible") so the sticky resolves
 * against the page scroll, and the negative margins bleed the band to the card edges — they must track
 * the panel body's own `px-4 sm:px-5`.
 *
 * Placement is deliberately breakpoint-independent: only the button set narrows, with the rest demoted
 * into "More". A bar anchored to the visual viewport to ride the soft keyboard was tried and removed —
 * the measurement differs per engine and display mode and lags scroll, so the bar drifted off the
 * keyboard on real hardware. Staying in the page's own coordinate system has no such failure mode.
 */
function FormattingToolbar({ editor, compact }: { editor: Editor; compact: boolean }) {
    const t = useT();
    const actions = useToolbarActions(editor);

    return (
        <div
            role="toolbar"
            aria-label={t('Formatting')}
            data-testid="compose-toolbar"
            className="sticky top-[var(--modern-top-offset)] z-10 -mx-4 flex flex-wrap items-center gap-0.5 border-b border-border bg-card px-4 py-1.5 pointer-coarse:gap-1 sm:-mx-5 sm:px-5"
        >
            {compact ? (
                <>
                    <ActionButton action={actions.bold} />
                    <ActionButton action={actions.h2} />
                    <ActionButton action={actions.bulletList} />
                    <LinkDialog editor={editor} />
                    <MoreMenu editor={editor} />
                </>
            ) : (
                <>
                    <ActionButton action={actions.bold} />
                    <ActionButton action={actions.italic} />
                    <ActionButton action={actions.strike} />
                    <ActionButton action={actions.code} />
                    <ActionButton action={actions.h2} />
                    <ActionButton action={actions.h3} />
                    <ActionButton action={actions.h4} />
                    <ActionButton action={actions.bulletList} />
                    <ActionButton action={actions.orderedList} />
                    <ActionButton action={actions.quote} />
                    <ActionButton action={actions.codeBlock} />
                    <LinkDialog editor={editor} />
                    <ActionButton action={actions.hr} />
                    <TableMenu editor={editor} />
                </>
            )}
        </div>
    );
}

/**
 * WYSIWYG compose editor for a Modern-surface Markdown body. The schema and the Markdown
 * parse/serialize round-trip live in editor-extensions.ts (the shared SSoT). Default export so the
 * host page can lazy-load it.
 */
export default function RichTextEditor({
    initialMarkdown,
    onChange,
    label,
    id,
    rows,
    'aria-required': ariaRequired,
    'aria-invalid': ariaInvalid,
    'aria-describedby': ariaDescribedby,
}: RichTextEditorProps) {
    // Keep onChange fresh without recreating the editor: the stable options below call the ref.
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    const attributes: Record<string, string> = { 'aria-label': label };
    if (id) {
        attributes.id = id;
    }
    if (rows) {
        attributes.style = composeEditorRowsStyle(rows);
    }
    if (ariaRequired) {
        attributes['aria-required'] = 'true';
    }
    if (ariaInvalid) {
        attributes['aria-invalid'] = 'true';
    }
    if (ariaDescribedby) {
        attributes['aria-describedby'] = ariaDescribedby;
    }

    const [baseOptions] = useState(() =>
        createComposeEditorOptions({
            initialMarkdown,
            onChange: (md) => onChangeRef.current(md),
            attributes,
        }),
    );

    const editor = useEditor({ ...baseOptions, immediatelyRender: false, shouldRerenderOnTransaction: true });

    // The construction-time attributes are read once; push changes (a validation error arriving
    // after mount must toggle aria-invalid/aria-describedby on the live editable).
    const attributesKey = JSON.stringify(attributes);
    useEffect(() => {
        editor?.setOptions({ editorProps: { attributes: composeEditorAttributes(JSON.parse(attributesKey) as Record<string, string>) } });
    }, [editor, attributesKey]);

    const isCompact = useIsCompact();

    return (
        <div className="space-y-2">
            {editor && <FormattingToolbar editor={editor} compact={isCompact} />}
            {editor && <EditorContent editor={editor} />}
        </div>
    );
}
