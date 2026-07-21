import { useId, useRef, useState, type ReactNode } from 'react';
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
    Quote,
    Rows3,
    SquareCode,
    Strikethrough,
    Table as TableIcon,
    Trash2,
} from 'lucide-react';
import { useT } from '@/lib/i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogTitle, SheetContent } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { createComposeEditorOptions } from '@/components/compose/editor-extensions';

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

const TOOLBAR_BUTTON_CLASS =
    'inline-flex size-9 items-center justify-center rounded-field text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40 aria-pressed:bg-accent aria-pressed:text-accent-foreground';

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
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            setOpen(false);
            return;
        }
        if (!/^https?:\/\//i.test(trimmed)) {
            setError(t('Enter an http:// or https:// address.'));
            return;
        }
        editor.chain().focus().extendMarkRange('link').setLink({ href: trimmed }).run();
        setOpen(false);
    }

    function remove() {
        editor.chain().focus().extendMarkRange('link').unsetLink().run();
        setOpen(false);
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <ToolbarButton label={t('Link')} pressed={active} onClick={openDialog}>
                <Link2 className="size-4" />
            </ToolbarButton>
            <SheetContent closeLabel={t('Close')}>
                <DialogTitle className="text-base font-semibold">{t('Link')}</DialogTitle>
                <form
                    className="mt-4 space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
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
            </SheetContent>
        </Dialog>
    );
}

/** Insert/edit table via a dropdown; row/column actions disable outside a table. */
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
                    onSelect={() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}
                >
                    <TableIcon className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Insert table')}</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().addRowAfter().run()}>
                    <Rows3 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Add row')}</span>
                </DropdownMenuItem>
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().deleteRow().run()}>
                    <Rows3 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Delete row')}</span>
                </DropdownMenuItem>
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().addColumnAfter().run()}>
                    <Columns3 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Add column')}</span>
                </DropdownMenuItem>
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().deleteColumn().run()}>
                    <Columns3 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Delete column')}</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem disabled={!inTable} onSelect={() => editor.chain().focus().deleteTable().run()}>
                    <Trash2 className="size-4 shrink-0 text-muted-foreground" />
                    <span className="flex-1">{t('Delete table')}</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function Toolbar({ editor }: { editor: Editor }) {
    const t = useT();

    return (
        <div role="toolbar" aria-label={t('Formatting')} className="flex flex-wrap items-center gap-0.5">
            <ToolbarButton label={t('Bold')} pressed={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>
                <Bold className="size-4" />
            </ToolbarButton>
            <ToolbarButton label={t('Italic')} pressed={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>
                <Italic className="size-4" />
            </ToolbarButton>
            <ToolbarButton
                label={t('Strikethrough')}
                pressed={editor.isActive('strike')}
                onClick={() => editor.chain().focus().toggleStrike().run()}
            >
                <Strikethrough className="size-4" />
            </ToolbarButton>
            <ToolbarButton label={t('Inline code')} pressed={editor.isActive('code')} onClick={() => editor.chain().focus().toggleCode().run()}>
                <Code className="size-4" />
            </ToolbarButton>
            <ToolbarButton
                label={t('Heading 2')}
                pressed={editor.isActive('heading', { level: 2 })}
                onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
            >
                <Heading2 className="size-4" />
            </ToolbarButton>
            <ToolbarButton
                label={t('Heading 3')}
                pressed={editor.isActive('heading', { level: 3 })}
                onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
            >
                <Heading3 className="size-4" />
            </ToolbarButton>
            <ToolbarButton
                label={t('Heading 4')}
                pressed={editor.isActive('heading', { level: 4 })}
                onClick={() => editor.chain().focus().toggleHeading({ level: 4 }).run()}
            >
                <Heading4 className="size-4" />
            </ToolbarButton>
            <ToolbarButton
                label={t('Bullet list')}
                pressed={editor.isActive('bulletList')}
                onClick={() => editor.chain().focus().toggleBulletList().run()}
            >
                <List className="size-4" />
            </ToolbarButton>
            <ToolbarButton
                label={t('Numbered list')}
                pressed={editor.isActive('orderedList')}
                onClick={() => editor.chain().focus().toggleOrderedList().run()}
            >
                <ListOrdered className="size-4" />
            </ToolbarButton>
            <ToolbarButton label={t('Quote')} pressed={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()}>
                <Quote className="size-4" />
            </ToolbarButton>
            <ToolbarButton
                label={t('Code block')}
                pressed={editor.isActive('codeBlock')}
                onClick={() => editor.chain().focus().toggleCodeBlock().run()}
            >
                <SquareCode className="size-4" />
            </ToolbarButton>
            <LinkDialog editor={editor} />
            <ToolbarButton label={t('Horizontal rule')} onClick={() => editor.chain().focus().setHorizontalRule().run()}>
                <Minus className="size-4" />
            </ToolbarButton>
            <TableMenu editor={editor} />
        </div>
    );
}

/**
 * WYSIWYG compose editor for a Modern-surface Markdown body. The schema and the Markdown
 * parse/serialize round-trip live in editor-extensions.ts (the shared SSoT). Default export so the
 * host page can lazy-load it. Not yet wired to any page — integration is a follow-up.
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

    const [baseOptions] = useState(() => {
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
        return createComposeEditorOptions({
            initialMarkdown,
            onChange: (md) => onChangeRef.current(md),
            attributes,
        });
    });

    const editor = useEditor({ ...baseOptions, immediatelyRender: false, shouldRerenderOnTransaction: true });

    return (
        <div className="space-y-2">
            {editor && <Toolbar editor={editor} />}
            <EditorContent editor={editor} />
        </div>
    );
}
