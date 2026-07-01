import type { ComponentProps } from 'react';
import { Dialog as DialogPrimitive } from 'radix-ui';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

export const Dialog = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose = DialogPrimitive.Close;
export const DialogTitle = DialogPrimitive.Title;

/** Dialog content rendered as a left-edge sheet (the mobile nav drawer). Radix supplies the focus
 *  trap, ESC/overlay dismissal, and scroll lock. */
export function SheetContent({
    className,
    children,
    closeLabel = 'Close',
    ...props
}: ComponentProps<typeof DialogPrimitive.Content> & { closeLabel?: string }) {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm" />
            <DialogPrimitive.Content
                className={cn(
                    'fixed inset-y-0 left-0 z-50 flex w-80 max-w-[85vw] flex-col gap-1 bg-white p-4 shadow-xl outline-none dark:bg-slate-900',
                    className,
                )}
                {...props}
            >
                {children}
                <DialogPrimitive.Close
                    className="absolute right-3 top-3 rounded-full p-1 text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                    aria-label={closeLabel}
                >
                    <X className="size-5" />
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
    );
}
