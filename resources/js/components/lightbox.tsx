import { Dialog as DialogPrimitive } from 'radix-ui';
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
 */
export function Lightbox({
    image,
    onClose,
    restoreFocus,
}: {
    image: LightboxImage | null;
    onClose: () => void;
    /** Focus target for dismissal — the thumbnails are plain buttons, not Radix triggers. */
    restoreFocus?: () => void;
}) {
    const t = useT();

    return (
        <DialogPrimitive.Root
            open={image !== null}
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
                    // Definite width, not max-width: a fixed box centered with left-1/2 + translate and
                    // width:auto shrink-to-fits to the ~50vw available right of its left edge, so the
                    // image would render at half screen width. A definite width lets it fill the frame.
                    className="fixed left-1/2 top-1/2 z-50 max-h-[92vh] w-[min(94vw,60rem)] -translate-x-1/2 -translate-y-1/2 rounded-xl bg-background p-3 text-foreground shadow-xl outline-none"
                >
                    <DialogPrimitive.Title className="sr-only">{t('Image')}</DialogPrimitive.Title>
                    {image && (
                        <div className="space-y-2.5">
                            <img src={image.url} alt="" className="mx-auto max-h-[80vh] max-w-full rounded-md" />
                            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
                                <a href={image.url} target="_blank" rel="noopener noreferrer" className="text-link hover:underline">
                                    {t('Open original in new tab')}
                                </a>
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
