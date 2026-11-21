import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { type MouseEvent, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { headingVariants } from '@/components/ui/heading';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { DESK_QUERY } from '@/lib/desk-query';
import { useT } from '@/lib/i18n';
import type { ChromeScope } from '@/lib/member-chrome';
import { type PlaceSectionItem, placeSections } from '@/lib/place-sections';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

type Variant = 'phone' | 'desk';

const deskNow = () => typeof window.matchMedia === 'function' && window.matchMedia(DESK_QUERY).matches;

/** A place's face, decorative: the name beside it is what is read. */
function PlaceFace({ scope }: { scope: ChromeScope }) {
    return scope.kind === 'group' ? (
        <CommunityImage name={scope.name} src={scope.imageUrl} className="size-6" textClassName="text-3xs" decorative />
    ) : (
        <Avatar id={scope.id} name={scope.name} src={scope.imageUrl} color={scope.avatarColor} isAi={scope.isAi} size="xs" decorative />
    );
}

/**
 * The trigger that has just become the visible one across `lg` may still sit in a header receded
 * off screen and inert, so focus waits a few frames for the shell to bring it back.
 */
function focusTriggerAt(variant: Variant, frames = 10) {
    requestAnimationFrame(() => {
        const trigger = document.querySelector<HTMLElement>(`[data-place-trigger="${variant}"]`);
        if (trigger && !trigger.closest('[inert]')) {
            trigger.focus();
        } else if (frames > 1) {
            focusTriggerAt(variant, frames - 1);
        }
    });
}

function PlaceSections({ items, variant, onPick }: { items: PlaceSectionItem[]; variant: Variant; onPick: (event: MouseEvent, item: PlaceSectionItem) => void }) {
    const t = useT();

    return (
        <ul className={cn('grid', variant === 'phone' ? 'grid-cols-5' : 'grid-flow-col auto-cols-[5.5rem] gap-1')}>
            {items.map((item) => (
                <li key={item.key}>
                    <Link
                        href={item.href}
                        aria-current={item.current}
                        onClick={(event: MouseEvent) => onPick(event, item)}
                        className={cn(
                            'flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl py-2 text-muted-foreground transition-colors hover:bg-accent motion-reduce:transition-none',
                            // 11px: five tiles across a 360px phone still fit a six-character name.
                            variant === 'phone' ? 'text-2xs' : 'px-1 text-xs',
                            item.current && 'bg-accent text-foreground',
                        )}
                    >
                        <item.icon className="size-6" strokeWidth={item.current ? 2.25 : 2} aria-hidden />
                        <span className="max-w-full truncate">{t(item.label.key, item.label.replacements)}</span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/**
 * The phone header and the desk place bar each hold one, both mounted at every width, so each keeps
 * its own open state (docs/internals/modern-frame.md, "Place sections").
 */
export function PlaceSwitch({ scope, variant }: { scope: ChromeScope; variant: Variant }) {
    const { component, props } = usePage<PageProps>();
    const [open, setOpen] = useState(false);
    // Why the panel is closing, read once by onCloseAutoFocus: focus goes back to the name only when the reader stays here.
    const leaving = useRef<'navigate' | 'resize' | null>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const items = placeSections(scope, String(component), props.enabledFeatures);
    // The phone panel spans the header it hangs from rather than the name.
    const header = useMemo(
        () => ({ current: { getBoundingClientRect: () => (triggerRef.current?.closest('header') ?? document.body).getBoundingClientRect() } }),
        [],
    );

    // The browser's back and forward swap the page under an open panel.
    useEffect(() => {
        if (!open) {
            return;
        }

        return router.on('navigate', () => {
            leaving.current = 'navigate';
            setOpen(false);
        });
    }, [open]);

    useEffect(() => {
        if (!open || typeof window.matchMedia !== 'function') {
            return;
        }
        const query = window.matchMedia(DESK_QUERY);
        const close = () => {
            leaving.current = 'resize';
            setOpen(false);
        };
        query.addEventListener('change', close);

        return () => query.removeEventListener('change', close);
    }, [open]);

    const pick = (event: MouseEvent, item: PlaceSectionItem) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
        }
        if (item.current === 'page') {
            event.preventDefault();
        } else {
            leaving.current = 'navigate';
        }
        setOpen(false);
    };

    const toggle = (next: boolean) => {
        // Reopened during its fade-out, the panel never ran `onCloseAutoFocus`, so the last close's reason is still set.
        if (next) {
            leaving.current = null;
        }
        setOpen(next);
    };

    return (
        <Popover open={open} onOpenChange={toggle} modal>
            {/* Radix's modal leaves a `pointer-events-auto` descendant pressable, so this takes every press outside the panel. */}
            {open && createPortal(<div aria-hidden data-place-backdrop className="pointer-events-auto fixed inset-0 z-40" />, document.body)}
            {variant === 'phone' && <PopoverAnchor virtualRef={header} />}
            <PopoverTrigger asChild>
                <button
                    ref={triggerRef}
                    type="button"
                    data-place-trigger={variant}
                    className={cn(
                        'group flex min-w-0 items-center gap-1 transition-colors motion-reduce:transition-none',
                        headingVariants({ variant: 'bar' }),
                        variant === 'phone'
                            ? 'min-h-11 justify-center px-1'
                            : // PlaceBar's pill height, which the shell's single `--modern-place-bar` offsets by.
                              'h-8 rounded-full pr-2 pl-1 hover:bg-accent data-[state=open]:bg-accent',
                    )}
                >
                    {variant === 'desk' && <PlaceFace scope={scope} />}
                    <span className="min-w-0 truncate">{scope.name}</span>
                    <ChevronDown
                        aria-hidden
                        className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none"
                    />
                </button>
            </PopoverTrigger>
            <PopoverContent
                aria-label={scope.name}
                side="bottom"
                align="start"
                fade
                {...(variant === 'phone' ? { shape: 'band', sideOffset: 0, collisionPadding: 0 } : { sideOffset: 4 })}
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    const panel = event.currentTarget as HTMLElement;
                    (panel.querySelector<HTMLElement>('[aria-current]') ?? panel.querySelector<HTMLElement>('a'))?.focus();
                }}
                onCloseAutoFocus={(event) => {
                    const reason = leaving.current;
                    leaving.current = null;
                    if (reason === 'navigate') {
                        event.preventDefault();
                    } else if (reason === 'resize') {
                        event.preventDefault();
                        focusTriggerAt(deskNow() ? 'desk' : 'phone');
                    }
                }}
            >
                <PlaceSections items={items} variant={variant} onPick={pick} />
            </PopoverContent>
        </Popover>
    );
}
