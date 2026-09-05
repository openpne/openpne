import { useId } from 'react';
import { Check, SlidersHorizontal } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItemIndicator,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tip } from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';
import type { InputMethod } from './editor-mode';

/**
 * Radix here, unlike the editor's own formatting overflow: picking an item rebuilds the editor
 * anyway, so menu semantics matter more than holding the soft keyboard open.
 */
export function InputMethodMenu({ value, onSelect }: { value: InputMethod; onSelect: (next: InputMethod) => void }) {
    const t = useT();
    const descId = useId();

    const items: { value: InputMethod; label: string; description: string }[] = [
        { value: 'rich', label: t('Use formatting buttons'), description: t('Set bold and headings with buttons') },
        { value: 'markdown', label: t('Use Markdown'), description: t('Mark up formatting with symbols like # and *') },
        { value: 'plain', label: t('No formatting'), description: t('Show the text exactly as typed, treating no symbol as formatting') },
    ];

    return (
        // Non-modal: a three-item menu that closes on pick needs no focus trap, and the modal variant
        // would aria-hidden the rest of the form while it is open (axe: aria-hidden-focus).
        <DropdownMenu modal={false}>
            <Tip label={t('Change input method')}>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        data-testid="compose-input-method-trigger"
                        className="-my-3 inline-flex size-8 pointer-coarse:size-11 items-center justify-center rounded-field text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <SlidersHorizontal className="size-4" />
                    </button>
                </DropdownMenuTrigger>
            </Tip>
            {/* Descriptions size the menu, so cap it to the viewport or it overflows a phone screen. */}
            <DropdownMenuContent
                align="end"
                aria-label={t('Input method')}
                data-testid="compose-input-method-menu"
                className="max-w-[calc(100vw-2rem)]"
            >
                <DropdownMenuLabel>{t('Input method')}</DropdownMenuLabel>
                <DropdownMenuRadioGroup value={value} onValueChange={(next) => onSelect(next as InputMethod)}>
                    {items.map((item) => (
                        <DropdownMenuRadioItem
                            key={item.value}
                            value={item.value}
                            // Concise accessible name; the second line is announced as the description.
                            aria-label={item.label}
                            aria-describedby={`${descId}-${item.value}`}
                        >
                            <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center text-selected">
                                <DropdownMenuItemIndicator>
                                    <Check className="size-4" />
                                </DropdownMenuItemIndicator>
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block">{item.label}</span>
                                <span id={`${descId}-${item.value}`} className="mt-0.5 block text-xs text-muted-foreground">
                                    {item.description}
                                </span>
                            </span>
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function InputMethodBadge({ method }: { method: InputMethod }) {
    const t = useT();

    if (method === 'rich') {
        return null;
    }

    return (
        <span data-testid="compose-input-method-badge" className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
            {method === 'markdown' ? t('Markdown') : t('No formatting')}
        </span>
    );
}
