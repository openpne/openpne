import {
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
    type ComponentType,
    type FocusEvent as ReactFocusEvent,
    type KeyboardEvent as ReactKeyboardEvent,
    type ReactNode,
    type RefObject,
} from 'react';
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
import { useVisualViewport, type ViewportMetrics } from '@/components/compose/use-visual-viewport-bottom';

type RichTextEditorProps = {
    initialMarkdown: string;
    onChange: (md: string) => void;
    /** Accessible name; forwarded as aria-label onto the editable and reused for the toolbar. */
    label: string;
    id?: string;
    // Hyphen-named DOM attributes exactly as components/ui/field.tsx clone-injects them.
    'aria-required'?: 'true';
    'aria-invalid'?: 'true';
    'aria-describedby'?: string;
};

// Below Tailwind's md (768px): the compact layout trades the full sticky row for the mobile bottom bar.
const COMPACT_QUERY = '(max-width: 767.98px)';

const FOCUSABLE_SELECTOR =
    'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"]),[contenteditable="true"]';

/**
 * The next tabbable element after `el` in document order. Because the bar is portalled to <body> (end
 * of document), the tabbable right after the wrapper sentinel is the in-flow control that follows the
 * editor (the "Edit as Markdown" button), not the portalled bar — so this is both the toolbar's forward
 * escape target and the control whose focus should keep the bar mounted (for symmetric reverse entry).
 */
function nextTabbableAfter(el: HTMLElement | null): HTMLElement | null {
    if (!el) {
        return null;
    }
    const focusables = Array.from(document.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
        (node) => node === el || node.getClientRects().length > 0,
    );
    const index = focusables.indexOf(el);
    return index >= 0 ? (focusables[index + 1] ?? null) : null;
}

// size-9 (36px) resting; pointer-coarse bumps every target to 44px (Apple HIG / Material touch floor).
const TOOLBAR_BUTTON_CLASS =
    'inline-flex size-9 pointer-coarse:size-11 items-center justify-center rounded-field text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40 aria-pressed:bg-accent aria-pressed:text-accent-foreground';

/** Track a media query so the mobile bottom bar only engages below md (matches the CSS breakpoint). */
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
 * Single source for every formatting command, so the desktop row and the mobile "More" menu can't
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

/** Text + icon row inside the mobile "More" menu — the label doubles as the icon's meaning on touch. */
function MoreItem({
    label,
    icon: Icon,
    pressed,
    disabled,
    onSelect,
}: {
    label: string;
    icon: ComponentType<{ className?: string }>;
    pressed?: boolean;
    disabled?: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            disabled={disabled}
            onMouseDown={(event) => event.preventDefault()}
            onClick={onSelect}
            className="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm text-foreground transition hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40 aria-pressed:bg-accent aria-pressed:text-accent-foreground"
        >
            <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            <span className="flex-1 text-left">{label}</span>
        </button>
    );
}

/**
 * Overflow menu for the demoted formatting + table commands on mobile. A plain (non-Radix) popover on
 * purpose: Radix menus pull DOM focus into the menu for arrow-key navigation, which dismisses the
 * soft keyboard and drops the editor selection. Here the trigger and items preventDefault on
 * mousedown, so focus never leaves the contenteditable — the keyboard stays up and commands apply to
 * the live selection. Opens upward (bottom-full) since the bar sits at the viewport bottom.
 */
function MoreMenu({ editor, viewportHeight }: { editor: Editor; viewportHeight: number }) {
    const t = useT();
    const actions = useToolbarActions(editor);
    const [open, setOpen] = useState(false);
    const [maxHeight, setMaxHeight] = useState<number | undefined>(undefined);
    const containerRef = useRef<HTMLDivElement>(null);
    const panelId = useId();
    const inTable = editor.isActive('table');

    // Clamp the upward-opening panel to the visible band above the bar so it never spills past the top
    // of the visual viewport when the keyboard is open: min(60vh of the layout viewport, vv.height −
    // bar height − margin), with no lower floor — a tiny visual viewport shrinks the panel to what
    // fits and the panel scrolls internally. viewportHeight 0 = unknown → keep the CSS 60vh cap.
    useLayoutEffect(() => {
        if (!open) {
            return;
        }
        const bar = containerRef.current?.closest('[data-testid="compose-mobile-toolbar"]') as HTMLElement | null;
        const barHeight = bar?.offsetHeight ?? 0;
        setMaxHeight(
            viewportHeight > 0 ? Math.min(0.6 * window.innerHeight, viewportHeight - barHeight - 16) : undefined,
        );
    }, [open, viewportHeight]);

    useEffect(() => {
        if (!open) {
            return;
        }
        const onPointerDown = (event: PointerEvent) => {
            if (!containerRef.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        // Close when keyboard focus leaves the panel (e.g. Tab from the last item onto the switch
        // button) so the popover never lingers over an external control.
        const onFocusIn = (event: FocusEvent) => {
            if (!containerRef.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', onPointerDown, true);
        document.addEventListener('focusin', onFocusIn);
        document.addEventListener('keydown', onKeyDown);
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
        <div ref={containerRef} className="relative">
            <button
                type="button"
                aria-label={t('More formatting')}
                aria-haspopup="true"
                aria-expanded={open}
                aria-controls={open ? panelId : undefined}
                title={t('More formatting')}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => setOpen((value) => !value)}
                className={cn(TOOLBAR_BUTTON_CLASS, open && 'bg-accent text-accent-foreground')}
            >
                <MoreHorizontal className="size-4" />
            </button>
            {open && (
                <div
                    id={panelId}
                    data-testid="compose-more-panel"
                    style={{ maxHeight }}
                    className="absolute bottom-full right-0 z-50 mb-2 max-h-[60vh] w-60 overflow-y-auto rounded-xl border border-border bg-card p-1 shadow-lg"
                >
                    <MoreItem label={t('Italic')} icon={Italic} pressed={actions.italic.active} onSelect={() => select(actions.italic.run)} />
                    <MoreItem label={t('Strikethrough')} icon={Strikethrough} pressed={actions.strike.active} onSelect={() => select(actions.strike.run)} />
                    <MoreItem label={t('Inline code')} icon={Code} pressed={actions.code.active} onSelect={() => select(actions.code.run)} />
                    <MoreItem label={t('Heading 3')} icon={Heading3} pressed={actions.h3.active} onSelect={() => select(actions.h3.run)} />
                    <MoreItem label={t('Heading 4')} icon={Heading4} pressed={actions.h4.active} onSelect={() => select(actions.h4.run)} />
                    <MoreItem
                        label={t('Numbered list')}
                        icon={ListOrdered}
                        pressed={actions.orderedList.active}
                        onSelect={() => select(actions.orderedList.run)}
                    />
                    <MoreItem label={t('Quote')} icon={Quote} pressed={actions.quote.active} onSelect={() => select(actions.quote.run)} />
                    <MoreItem label={t('Code block')} icon={SquareCode} pressed={actions.codeBlock.active} onSelect={() => select(actions.codeBlock.run)} />
                    <MoreItem label={t('Horizontal rule')} icon={Minus} onSelect={() => select(actions.hr.run)} />
                    <div role="separator" className="my-1 h-px bg-border" />
                    <MoreItem
                        label={t('Insert table')}
                        icon={TableIcon}
                        onSelect={() => select(() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).scrollIntoView().run())}
                    />
                    <MoreItem
                        label={t('Add row')}
                        icon={Rows3}
                        disabled={!inTable}
                        onSelect={() => select(() => editor.chain().focus().addRowAfter().scrollIntoView().run())}
                    />
                    <MoreItem
                        label={t('Delete row')}
                        icon={Rows3}
                        disabled={!inTable}
                        onSelect={() => select(() => editor.chain().focus().deleteRow().scrollIntoView().run())}
                    />
                    <MoreItem
                        label={t('Add column')}
                        icon={Columns3}
                        disabled={!inTable}
                        onSelect={() => select(() => editor.chain().focus().addColumnAfter().scrollIntoView().run())}
                    />
                    <MoreItem
                        label={t('Delete column')}
                        icon={Columns3}
                        disabled={!inTable}
                        onSelect={() => select(() => editor.chain().focus().deleteColumn().scrollIntoView().run())}
                    />
                    <MoreItem
                        label={t('Delete table')}
                        icon={Trash2}
                        disabled={!inTable}
                        onSelect={() => select(() => editor.chain().focus().deleteTable().scrollIntoView().run())}
                    />
                </div>
            )}
        </div>
    );
}

/** Small dialog to set or clear the link on the current selection; only http/https is accepted. */
function LinkDialog({ editor, onOpenChange }: { editor: Editor; onOpenChange?: (open: boolean) => void }) {
    const t = useT();
    const [open, setOpenState] = useState(false);
    const [url, setUrl] = useState('');
    const [error, setError] = useState('');
    const urlId = useId();
    const errorId = `${urlId}-error`;

    const active = editor.isActive('link');

    const setOpen = (next: boolean) => {
        setOpenState(next);
        onOpenChange?.(next);
    };

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
                // The default returns focus to the trigger, but on mobile the trigger lives in the
                // bottom bar, which unmounts as the overlay closes — so focus would fall to <body> and
                // the bar would not re-activate. Focusing the editor lands focus inside the wrapper and
                // re-arms the focusin activation, keeping the bar mounted through the handoff.
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

/** Insert/edit table via a dropdown; row/column actions disable outside a table. Desktop only. */
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
 * md+ toolbar: the full formatting row, sticky at the top of the form column (below the persistent
 * TopNav via --modern-top-offset, which is 0 at lg) with an opaque background so the body scrolls
 * under it. The host Panel opts out of overflow clipping (Panel overflow="visible") so the sticky
 * resolves against the page scroll, and -mx-5 bleeds the band to the card edges.
 */
function DesktopToolbar({ editor }: { editor: Editor }) {
    const t = useT();
    const actions = useToolbarActions(editor);

    return (
        <div
            role="toolbar"
            aria-label={t('Formatting')}
            className="sticky top-[var(--modern-top-offset)] z-10 -mx-5 flex flex-wrap items-center gap-0.5 border-b border-border bg-card px-5 py-1.5 pointer-coarse:gap-1"
        >
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
        </div>
    );
}

/**
 * Below md: a note.com-style bar pinned to the visual-viewport bottom so it rides just above the
 * keyboard. Positioned by TOP in layout-viewport coordinates (`top = viewportBottom − barHeight`),
 * robust whether iOS resizes the layout viewport, pans the visual viewport, or both. Rendered through
 * a portal to <body> so no ancestor transform/filter/backdrop-filter can capture the fixed position
 * (an iOS WebKit containing-block trap that made the bar float mid-screen at the content-column width).
 * At rest (keyboard closed) it pads for the home-indicator safe area; the More panel is a DOM child and
 * rides along.
 */
function MobileToolbar({
    editor,
    metrics,
    rootRef,
    sentinelRef,
    onOverlayOpenChange,
}: {
    editor: Editor;
    metrics: ViewportMetrics;
    rootRef: RefObject<HTMLDivElement | null>;
    sentinelRef: RefObject<HTMLSpanElement | null>;
    onOverlayOpenChange: (open: boolean) => void;
}) {
    const t = useT();
    const actions = useToolbarActions(editor);
    const [barHeight, setBarHeight] = useState(56);

    // Tab escape routes that compensate for the portal (the bar is not a DOM neighbour of the editor):
    // Shift+Tab off the first control returns to the editable; Tab off the last control jumps to the
    // in-flow control after the wrapper sentinel ("Edit as Markdown"). Forward/backward ENTRY is handled
    // by the sentinel's onFocus in the parent. `:not([disabled])` so a disabled table op is never an edge.
    const onKeyDown = (event: ReactKeyboardEvent) => {
        if (event.key !== 'Tab') {
            return;
        }
        const buttons = rootRef.current?.querySelectorAll<HTMLElement>('button:not([disabled])');
        if (!buttons || buttons.length === 0) {
            return;
        }
        const activeEl = document.activeElement;
        if (event.shiftKey && activeEl === buttons[0]) {
            event.preventDefault();
            editor.commands.focus();
        } else if (!event.shiftKey && activeEl === buttons[buttons.length - 1]) {
            event.preventDefault();
            nextTabbableAfter(sentinelRef.current)?.focus();
        }
    };

    // Measure the bar (its height varies with the safe-area padding) so the top offset lands its bottom
    // edge exactly on the visual-viewport bottom. ResizeObserver keeps it correct as the safe-area
    // padding toggles with the keyboard.
    useLayoutEffect(() => {
        const el = rootRef.current;
        if (!el) {
            return;
        }
        const measure = () => setBarHeight(el.offsetHeight);
        measure();
        if (typeof ResizeObserver === 'undefined') {
            return;
        }
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        return () => observer.disconnect();
    }, [rootRef]);

    if (typeof document === 'undefined') {
        return null;
    }

    // Portalled to <body>, the bar sits outside the page's <main>, so it carries its own region
    // landmark (axe "region") — the inner element keeps the toolbar role.
    return createPortal(
        <div
            ref={rootRef}
            role="region"
            aria-label={t('Editor toolbar')}
            data-testid="compose-mobile-toolbar"
            style={{ top: metrics.viewportBottom - barHeight }}
            onKeyDown={onKeyDown}
            className={cn(
                'fixed inset-x-0 z-40 border-t border-border bg-card px-2 py-1.5 shadow-elevated',
                !metrics.keyboardOpen && 'pb-[calc(0.375rem+env(safe-area-inset-bottom))]',
            )}
        >
            <div role="toolbar" aria-label={t('Formatting')} className="flex items-center gap-0.5 pointer-coarse:gap-1">
                <ActionButton action={actions.bold} />
                <ActionButton action={actions.h2} />
                <ActionButton action={actions.bulletList} />
                <LinkDialog editor={editor} onOpenChange={onOverlayOpenChange} />
                <MoreMenu editor={editor} viewportHeight={metrics.height} />
            </div>
        </div>,
        document.body,
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

    // Mobile bottom bar shows only while the editor surface holds focus.
    const isCompact = useIsCompact();
    const wrapperRef = useRef<HTMLDivElement>(null);
    const barPortalRef = useRef<HTMLDivElement>(null);
    const sentinelRef = useRef<HTMLSpanElement>(null);
    const blurTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const [focusWithin, setFocusWithin] = useState(false);
    const [overlayOpen, setOverlayOpen] = useState(false);
    const active = focusWithin || overlayOpen;
    const mobileActive = isCompact && active;

    // ACTIVATION: React synthetic onFocus bubbles through the portal, so focus entering the editor, the
    // sentinel, or the portalled bar activates the bar.
    const handleFocusIn = () => {
        clearTimeout(blurTimer.current);
        setFocusWithin(true);
    };

    // DEACTIVATION AUTHORITY (only while active), document-level so it also sees departures from the
    // portalled bar and the grace control, which the wrapper's React subtree cannot. Dual mechanism:
    //   - focusout SCHEDULES the deactivation timer on EVERY focus departure — including blur-to-nowhere
    //     (el.blur(), tapping non-focusable chrome, iOS keyboard "Done"), where activeElement becomes
    //     <body> and NO focusin ever fires.
    //   - focusin CANCELS it when focus lands back inside the allowed set: the wrapper, the portalled
    //     bar, or the one grace control (the "Edit as Markdown" button after the sentinel, kept allowed
    //     so Shift+Tab reverse-entry stays deterministic).
    // Net: bar→grace = focusin cancels; grace→visibility = outside focusin never cancels, timer fires;
    // blur-to-null = nothing cancels, timer fires. The 100ms delay lets Radix's link-sheet close refocus
    // the editable in time (and overlayOpen pins the bar through the sheet's lifetime regardless).
    useEffect(() => {
        if (!mobileActive) {
            return;
        }
        const inAllowedSet = (target: EventTarget | null): boolean => {
            const grace = nextTabbableAfter(sentinelRef.current);
            return (
                target instanceof Node &&
                (Boolean(wrapperRef.current?.contains(target)) ||
                    Boolean(barPortalRef.current?.contains(target)) ||
                    (grace !== null && target === grace))
            );
        };
        const onDocFocusOut = () => {
            clearTimeout(blurTimer.current);
            blurTimer.current = setTimeout(() => setFocusWithin(false), 100);
        };
        const onDocFocusIn = (event: FocusEvent) => {
            if (inAllowedSet(event.target)) {
                clearTimeout(blurTimer.current);
            }
        };
        document.addEventListener('focusout', onDocFocusOut);
        document.addEventListener('focusin', onDocFocusIn);
        return () => {
            clearTimeout(blurTimer.current);
            document.removeEventListener('focusout', onDocFocusOut);
            document.removeEventListener('focusin', onDocFocusIn);
        };
    }, [mobileActive]);

    // Focus-order bridge for the portalled bar: Tab out of the editor lands on this in-flow sentinel,
    // which forwards into the portalled toolbar (first button on forward entry from the editor; last
    // control on backward entry via Shift+Tab from the following control). The bar's own keydown handles
    // the reverse escapes. Rendered only while the bar is mounted, so it is never a stray tab stop.
    const handleSentinelFocus = (event: ReactFocusEvent) => {
        const buttons = barPortalRef.current?.querySelectorAll<HTMLElement>('button:not([disabled])');
        if (!buttons || buttons.length === 0) {
            return;
        }
        const fromInsideWrapper = event.relatedTarget instanceof Node && wrapperRef.current?.contains(event.relatedTarget);
        (fromInsideWrapper ? buttons[0] : buttons[buttons.length - 1])?.focus();
    };

    const viewport = useVisualViewport(mobileActive);

    return (
        <div ref={wrapperRef} className="space-y-2" onFocus={handleFocusIn}>
            {editor && !isCompact && <DesktopToolbar editor={editor} />}
            {editor && <EditorContent editor={editor} />}
            {editor && mobileActive && (
                <>
                    <span
                        ref={sentinelRef}
                        data-testid="compose-focus-sentinel"
                        tabIndex={0}
                        onFocus={handleSentinelFocus}
                        className="sr-only"
                    />
                    <MobileToolbar
                        editor={editor}
                        metrics={viewport}
                        rootRef={barPortalRef}
                        sentinelRef={sentinelRef}
                        onOverlayOpenChange={setOverlayOpen}
                    />
                </>
            )}
        </div>
    );
}
