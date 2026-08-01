import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { useEffect, useRef } from 'react';
import { useT } from '@/lib/i18n';

interface LightboxImage {
    url: string;
}

/**
 * Full-size image viewer over the current page — the Modern counterpart of the admin
 * image-lightbox (same interaction contract: overlay/Esc/close dismiss, plus an
 * open-the-original escape hatch). Built on the same Radix Dialog primitive as the nav
 * drawer and confirm dialog, sharing their overlay style: it supplies the focus trap,
 * dismissal, scroll lock and focus restore.
 *
 * Controlled multi-image viewer: the parent owns which of `images` is shown via `index`
 * (null = closed) and moves it with `onNavigate`. Navigation is bounded — no wrap — so the
 * prev/next affordances and the counter only exist for a set of more than one.
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
    const touchStart = useRef<{ x: number; y: number } | null>(null);

    const image = index === null ? null : (images[index] ?? null);
    const hasPrev = index !== null && index > 0;
    const hasNext = index !== null && index < images.length - 1;

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

    // Start fetching the neighbours so a swipe/arrow rarely waits on the network.
    useEffect(() => {
        if (index === null) {
            return;
        }
        for (const adjacent of [images[index - 1], images[index + 1]]) {
            if (adjacent) {
                new Image().src = adjacent.url;
            }
        }
    }, [index, images]);

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
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm" />
                <DialogPrimitive.Content
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
                    onTouchStart={(e) => {
                        // Drop the anchor on a second finger so a pinch-zoom never resolves as a swipe.
                        const point = e.touches[0];
                        touchStart.current = e.touches.length === 1 && point ? { x: point.clientX, y: point.clientY } : null;
                    }}
                    onTouchEnd={(e) => {
                        const start = touchStart.current;
                        touchStart.current = null;
                        const touch = e.changedTouches[0];
                        if (!start || !touch) {
                            return;
                        }
                        const dx = touch.clientX - start.x;
                        const dy = touch.clientY - start.y;
                        // Horizontal dominance gate: a mostly-vertical drag stays a scroll, not a page turn.
                        if (Math.abs(dx) < 48 || Math.abs(dx) <= Math.abs(dy)) {
                            return;
                        }
                        if (dx < 0) {
                            goNext();
                        } else {
                            goPrev();
                        }
                    }}
                    // touch-action pan-y keeps horizontal pans ours (the swipe) while leaving vertical
                    // scroll and pinch-zoom of the image to the browser.
                    // Definite width, not max-width: a fixed box centered with left-1/2 + translate and
                    // width:auto shrink-to-fits to the ~50vw available right of its left edge, so the
                    // image would render at half screen width. A definite width lets it fill the frame.
                    // Side padding carries the landscape insets — at 94vw the panel's edges (and with
                    // them the prev/next buttons) reach into the cutout, and landscape is the
                    // orientation an image gets viewed in.
                    // Max height counts the top/bottom insets too: a plain 92vh leaves only 4vh below
                    // a centered panel, less than a landscape home-indicator inset, and the centered
                    // 2rem-plus-insets margin splits so that each half clears its own side's inset.
                    // overflow-y-auto contains the overshoot when the image cap plus a wrapped footer
                    // still exceeds the panel — scrolled, not spilling the footer under the indicator.
                    className="fixed left-1/2 top-1/2 z-50 max-h-[calc(100dvh-2rem-env(safe-area-inset-top)-env(safe-area-inset-bottom))] w-[min(94vw,60rem)] -translate-x-1/2 -translate-y-1/2 touch-pan-y touch-pinch-zoom overflow-y-auto rounded-xl bg-background p-3 pr-[calc(0.75rem+env(safe-area-inset-right))] pl-[calc(0.75rem+env(safe-area-inset-left))] text-foreground shadow-xl outline-none"
                >
                    <DialogPrimitive.Title className="sr-only">{t('Image')}</DialogPrimitive.Title>
                    {image && (
                        <div className="space-y-2.5">
                            <div className="relative">
                                {/* The image cap reserves ~9rem inside the panel's max height for the
                                    footer row, paddings, and the centering margin, so a single-line
                                    footer fits without scrolling; the panel scrolls only when a
                                    wrapped footer outgrows the reserve. */}
                                <img
                                    src={image.url}
                                    alt=""
                                    className="mx-auto max-h-[calc(100dvh-9rem-env(safe-area-inset-top)-env(safe-area-inset-bottom))] max-w-full rounded-md"
                                />
                                {images.length > 1 && (
                                    <>
                                        {/* aria-disabled, not disabled: a focused button that turns disabled drops
                                            focus to body, which would kill arrow-key navigation at either end. */}
                                        <button
                                            type="button"
                                            onClick={goPrev}
                                            aria-disabled={!hasPrev}
                                            aria-label={t('Previous image')}
                                            className="absolute left-2 top-1/2 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-scrim-foreground transition-colors hover:bg-black/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-disabled:opacity-40 aria-disabled:hover:bg-black/40"
                                        >
                                            <ChevronLeft className="size-6" aria-hidden />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={goNext}
                                            aria-disabled={!hasNext}
                                            aria-label={t('Next image')}
                                            className="absolute right-2 top-1/2 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 text-scrim-foreground transition-colors hover:bg-black/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-disabled:opacity-40 aria-disabled:hover:bg-black/40"
                                        >
                                            <ChevronRight className="size-6" aria-hidden />
                                        </button>
                                    </>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
                                <a href={image.url} target="_blank" rel="noopener noreferrer" className="text-link hover:underline">
                                    {t('Open original in new tab')}
                                </a>
                                {index !== null && images.length > 1 && (
                                    <span aria-live="polite" className="text-muted-foreground">
                                        {index + 1} / {images.length}
                                    </span>
                                )}
                                <DialogPrimitive.Close className="text-muted-foreground transition-colors hover:text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                    {t('Close')}
                                </DialogPrimitive.Close>
                            </div>
                        </div>
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
