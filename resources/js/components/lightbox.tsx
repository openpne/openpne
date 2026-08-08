import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { type CSSProperties } from 'react';
import { useT } from '@/lib/i18n';
import { useSwipeDeck } from '@/lib/use-swipe-deck';
import { cn } from '@/lib/utils';

interface LightboxImage {
    url: string;
}

/**
 * Full-size image viewer over the current page — the Modern counterpart of the admin
 * image-lightbox (same interaction contract: overlay/Esc/close dismiss, plus an
 * open-the-original escape hatch). Built on the same Radix Dialog primitive as the nav
 * drawer and confirm dialog: it supplies the focus trap, dismissal, scroll lock and focus
 * restore.
 *
 * Controlled multi-image viewer: the parent owns which of `images` is shown via `index`
 * (null = closed) and moves it with `onNavigate`. Navigation is bounded — no wrap — so the
 * prev/next affordances and the position readout only exist for a set of more than one.
 */
export function Lightbox({
    images,
    index,
    onClose,
    onNavigate,
    restoreFocus,
}: {
    images: LightboxImage[];
    index: number | null;
    onClose: () => void;
    onNavigate: (index: number) => void;
    /** Focus target for dismissal — the thumbnails are plain buttons, not Radix triggers. */
    restoreFocus?: () => void;
}) {
    const t = useT();
    const deck = useSwipeDeck({ open: index !== null, index: index ?? 0, count: images.length, onNavigate, onClose });

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
                    // Scrim-mounted, not a panel: every pixel the frame kept was one the image lost.
                    // `fixed inset-0` also retires the 100dvh arithmetic this replaces — on iOS a
                    // fixed inset resolves against the small viewport, and the URL bar cannot move
                    // while the dialog holds the scroll lock.
                    // The chrome heights are declared once here and read by the bar, the dots and the
                    // slide padding, so a tall image can never end up under either of them.
                    // touch-action pinch-zoom, with no pan: the browser keeps two-finger zoom and
                    // hands over every one-finger pan, because a drag in any direction is ours —
                    // sideways turns the page, up or down closes the viewer.
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
                        <DialogPrimitive.Close
                            aria-label={t('Close')}
                            className="pointer-events-auto flex size-10 items-center justify-center rounded-full bg-black/40 text-scrim-foreground transition-colors hover:bg-black/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <X className="size-5" aria-hidden />
                        </DialogPrimitive.Close>
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
                                            // A finger already has a way out — drag the picture aside —
                                            // and tapping for it would race double-tap zoom and
                                            // press-and-hold save. Anything else keeps click-outside,
                                            // pen included: the drag is built on touch events, which a
                                            // pen does not have to produce.
                                            const pointerType = (e.nativeEvent as PointerEvent).pointerType;
                                            if (pointerType === 'touch' || deck.draggedRecently()) {
                                                return;
                                            }
                                            onClose();
                                        }}
                                        // From sm up the wide side padding is the chevrons' own lane,
                                        // so the image never runs under them. Below sm they overlap
                                        // instead: a cursor in a phone-width window would otherwise
                                        // spend a third of the width on two buttons, and a cursor has
                                        // no swipe to fall back on, so the arrows have to stay.
                                        className="flex h-full w-full shrink-0 items-center justify-center pb-[var(--lb-chrome-bottom)] pt-[var(--lb-chrome-top)] pl-[calc(0.5rem+env(safe-area-inset-left))] pr-[calc(0.5rem+env(safe-area-inset-right))] sm:pointer-fine:pl-[calc(4rem+env(safe-area-inset-left))] sm:pointer-fine:pr-[calc(4rem+env(safe-area-inset-right))]"
                                    >
                                        <img
                                            src={image.url}
                                            alt=""
                                            draggable={false}
                                            // Every image is in the DOM, so the browser fetches the set
                                            // on open (three at most, PostImages::MAX_IMAGES). Raise
                                            // that ceiling and this needs to become a window around
                                            // `index` instead.
                                            fetchPriority={i === index ? 'high' : 'auto'}
                                            className="max-h-full max-w-full"
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Dots rather than a counter: at three images a glanceable shape beats reading a
                        fraction, and it frees the bar of a third element that pushed the readout off
                        centre. Past a handful of images this stops scaling — swap it back for the
                        number. The screen-reader readout is the sr-only text beside it. */}
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

                    {/* Only where there is a pointer to aim: a touch device turns pages by swiping, and
                        a viewport-width rule would have put chevrons back on a phone held sideways.
                        aria-disabled, not disabled: a focused button that turns disabled drops focus
                        to body, which would kill arrow-key navigation at either end. */}
                    {many && (
                        <>
                            <button
                                type="button"
                                onClick={goPrev}
                                aria-disabled={!hasPrev}
                                aria-label={t('Previous image')}
                                className="lightbox-chrome absolute left-[calc(0.5rem+env(safe-area-inset-left))] top-1/2 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-scrim-foreground hover:bg-black/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-disabled:opacity-40 aria-disabled:hover:bg-black/40 pointer-fine:flex sm:size-12"
                            >
                                <ChevronLeft className="size-6" aria-hidden />
                            </button>
                            <button
                                type="button"
                                onClick={goNext}
                                aria-disabled={!hasNext}
                                aria-label={t('Next image')}
                                className="lightbox-chrome absolute right-[calc(0.5rem+env(safe-area-inset-right))] top-1/2 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-scrim-foreground hover:bg-black/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-disabled:opacity-40 aria-disabled:hover:bg-black/40 pointer-fine:flex sm:size-12"
                            >
                                <ChevronRight className="size-6" aria-hidden />
                            </button>
                        </>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
