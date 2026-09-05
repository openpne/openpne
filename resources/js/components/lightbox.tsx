import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { type CSSProperties } from 'react';
import { Tip } from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';
import { useSwipeDeck } from '@/lib/use-swipe-deck';
import { cn } from '@/lib/utils';

interface LightboxImage {
    url: string;
}

/**
 * Controlled: the parent owns which of `images` is shown through `index` (null = closed) and moves it
 * with `onNavigate`. Navigation is bounded, never wrapping, so the affordances exist only for a set
 * of more than one.
 */
export function Lightbox({
    images,
    index,
    onClose,
    onNavigate,
    restoreFocus,
    originRect,
}: {
    images: LightboxImage[];
    index: number | null;
    onClose: () => void;
    onNavigate: (index: number) => void;
    /** Focus target for dismissal — the thumbnails are plain buttons, not Radix triggers. */
    restoreFocus?: () => void;
    /** Where the shown image sits on the page below, so closing can hand it back. */
    originRect?: (index: number) => DOMRect | null;
}) {
    const t = useT();
    const deck = useSwipeDeck({ open: index !== null, index: index ?? 0, count: images.length, onNavigate, onClose, originRect });

    const hasPrev = index !== null && index > 0;
    const hasNext = index !== null && index < images.length - 1;
    const many = images.length > 1;

    const goPrev = () => {
        if (index !== null && index > 0) {
            onNavigate(index - 1);
        }
    };
    const goNext = () => {
        if (index !== null && index < images.length - 1) {
            onNavigate(index + 1);
        }
    };

    return (
        <DialogPrimitive.Root
            open={index !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogPrimitive.Portal>
                {/* No backdrop-blur: a full-viewport backdrop-filter recomposites every frame, which
                    is the one thing a finger-tracking drag cannot afford. */}
                <DialogPrimitive.Overlay ref={deck.scrimRef} className="lightbox-scrim fixed inset-0 z-50 bg-black/90" />
                <DialogPrimitive.Content
                    ref={deck.contentRef}
                    aria-describedby={undefined}
                    onCloseAutoFocus={(e) => {
                        if (restoreFocus) {
                            e.preventDefault();
                            restoreFocus();
                        }
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowLeft') {
                            goPrev();
                        } else if (e.key === 'ArrowRight') {
                            goNext();
                        }
                    }}
                    // On iOS a fixed inset resolves against the small viewport, and the URL bar
                    // cannot move while the dialog holds the scroll lock.

                    // The chrome heights are declared once here and read by the bar, the dots and the
                    // slide padding, so a tall image can never end up under either of them.

                    // touch-action pinch-zoom, with no pan: the browser keeps two-finger zoom and
                    // hands over every one-finger pan, because a drag in any direction is ours.
                    style={
                        {
                            '--lb-chrome-top': 'calc(3.5rem + env(safe-area-inset-top))',
                            '--lb-chrome-bottom': 'calc(2.5rem + env(safe-area-inset-bottom))',
                        } as CSSProperties
                    }
                    className="fixed inset-0 z-50 touch-pinch-zoom text-scrim-foreground outline-none"
                >
                    <DialogPrimitive.Title className="sr-only">{t('Image')}</DialogPrimitive.Title>

                    {/* pointer-events-none so the gaps between the two controls stay part of the
                        scrim and still dismiss; each control opts back in. */}
                    <div className="lightbox-chrome pointer-events-none absolute inset-x-0 top-0 z-10 flex h-[var(--lb-chrome-top)] items-center justify-between pl-[calc(0.5rem+env(safe-area-inset-left))] pr-[calc(0.5rem+env(safe-area-inset-right))] pt-[env(safe-area-inset-top)]">
                        <Tip label={t('Close')}>
                            <DialogPrimitive.Close className="pointer-events-auto flex size-10 items-center justify-center rounded-full bg-black/40 text-scrim-foreground transition-colors hover:bg-black/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                <X className="size-5" aria-hidden />
                            </DialogPrimitive.Close>
                        </Tip>
                        {index !== null && (
                            <a
                                href={images[index]?.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="pointer-events-auto rounded-sm text-sm hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                {t('Open in new tab')}
                            </a>
                        )}
                    </div>

                    <div {...deck.handlers} className="absolute inset-0 overflow-hidden">
                        <div ref={deck.stageRef} className="lightbox-stage h-full w-full">
                            <div style={{ '--lb-index': index ?? 0 } as CSSProperties} className="lightbox-track flex h-full w-full">
                                {images.map((image, i) => (
                                    <div
                                        key={i}
                                        data-active={i === index ? '' : undefined}
                                        onClick={(e) => {
                                            // Only the letterbox around the image dismisses.
                                            if (e.target !== e.currentTarget) {
                                                return;
                                            }
                                            // Touch is excluded because a tap would race double-tap
                                            // zoom and a drag already dismisses; a pen keeps
                                            // click-outside, the drag being built on touch events it
                                            // need not produce.
                                            const pointerType = (e.nativeEvent as PointerEvent).pointerType;
                                            if (pointerType === 'touch' || deck.draggedRecently()) {
                                                return;
                                            }
                                            onClose();
                                        }}
                                        // From sm up the wide padding is the chevrons' own lane, so
                                        // the image never runs under them; below sm they overlap, a
                                        // cursor having no swipe to fall back on.
                                        className="lightbox-slide flex h-full w-full shrink-0 items-center justify-center pb-[var(--lb-chrome-bottom)] pt-[var(--lb-chrome-top)] pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)] sm:pointer-fine:pl-[calc(4rem+env(safe-area-inset-left))] sm:pointer-fine:pr-[calc(4rem+env(safe-area-inset-right))]"
                                    >
                                        <img
                                            src={image.url}
                                            alt=""
                                            draggable={false}
                                            // Every image is in the DOM, so the browser fetches the
                                            // whole set on open; past PostImages::MAX_IMAGES this has
                                            // to become a window around `index` instead.
                                            fetchPriority={i === index ? 'high' : 'auto'}
                                            className="max-h-full max-w-full"
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Dots stop scaling past a handful of images — swap them back for the number;
                        the screen-reader readout is the sr-only text beside them. */}
                    {many && index !== null && (
                        <div className="lightbox-chrome pointer-events-none absolute inset-x-0 bottom-0 z-10 flex h-[var(--lb-chrome-bottom)] items-center justify-center pb-[env(safe-area-inset-bottom)]">
                            <span aria-live="polite" aria-atomic="true" className="sr-only">
                                {index + 1} / {images.length}
                            </span>
                            <span aria-hidden className="flex gap-1.5">
                                {images.map((_, i) => (
                                    <span
                                        key={i}
                                        className={cn('size-1.5 rounded-full', i === index ? 'bg-scrim-foreground' : 'bg-scrim-foreground/40')}
                                    />
                                ))}
                            </span>
                        </div>
                    )}

                    {/* Only where there is a pointer to aim: a touch device turns pages by swiping. */}
                    {/* The chrome fade belongs on this wrapper: one element cannot carry both it and the
                        end of the deck. */}
                    {/* aria-disabled, not disabled: a focused button that turns disabled drops focus to
                        body, which would kill arrow-key navigation at either end. */}
                    {/* Faint by ink, never by the element's opacity, which would take the focus ring
                        down with it. */}
                    {many && (
                        <div className="lightbox-chrome pointer-events-none absolute inset-0">
                            <Tip label={t('Previous image')}>
                                <button
                                    type="button"
                                    onClick={goPrev}
                                    aria-disabled={!hasPrev}
                                    className="left-[calc(0.5rem+env(safe-area-inset-left))] pointer-events-auto absolute top-1/2 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-scrim-foreground hover:bg-black/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-disabled:bg-transparent aria-disabled:text-scrim-foreground/25 aria-disabled:hover:bg-transparent pointer-fine:flex sm:size-12"
                                >
                                    <ChevronLeft className="size-6" aria-hidden />
                                </button>
                            </Tip>
                            <Tip label={t('Next image')}>
                                <button
                                    type="button"
                                    onClick={goNext}
                                    aria-disabled={!hasNext}
                                    className="right-[calc(0.5rem+env(safe-area-inset-right))] pointer-events-auto absolute top-1/2 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-scrim-foreground hover:bg-black/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-disabled:bg-transparent aria-disabled:text-scrim-foreground/25 aria-disabled:hover:bg-transparent pointer-fine:flex sm:size-12"
                                >
                                    <ChevronRight className="size-6" aria-hidden />
                                </button>
                            </Tip>
                        </div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
