import { useEffect, useState, type ReactNode } from 'react';
import { AlertDialog } from 'radix-ui';

export type ConfirmOptions = {
    title: string;
    description?: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Irreversible action; the confirm button turns red. */
    danger?: boolean;
};

type ResolvedOptions = ConfirmOptions & { resolve: (ok: boolean) => void };

const EVENT_NAME = 'modern:confirm-request';

/**
 * Promise-based confirmation. Dispatches a window event that <ConfirmDialogHost /> (mounted once in
 * the app shell) answers, so callers need no context provider:
 *
 *   const confirm = useConfirm();
 *   if (!(await confirm({ title, description, danger: true }))) return;
 */
export function useConfirm() {
    return (options: ConfirmOptions): Promise<boolean> =>
        new Promise<boolean>((resolve) => {
            window.dispatchEvent(new CustomEvent<ResolvedOptions>(EVENT_NAME, { detail: { ...options, resolve } }));
        });
}

/**
 * Host for useConfirm(), mounted once in the app shell. Radix AlertDialog supplies the focus trap,
 * ESC/overlay dismissal, scroll lock, and ARIA wiring. Dismissal resolves false; the caller runs
 * the action (and any loading UI) after an awaited true.
 */
export function ConfirmDialogHost() {
    const [opts, setOpts] = useState<ResolvedOptions | null>(null);

    useEffect(() => {
        const handler = (e: Event) => setOpts((e as CustomEvent<ResolvedOptions>).detail);
        window.addEventListener(EVENT_NAME, handler);
        return () => window.removeEventListener(EVENT_NAME, handler);
    }, []);

    // A confirm resolves true and closes; any other close (cancel/ESC/overlay) resolves false.
    // Whichever fires first wins — the promise ignores the second resolve.
    const settle = (ok: boolean) => {
        opts?.resolve(ok);
        setOpts(null);
    };

    return (
        <AlertDialog.Root
            open={opts !== null}
            onOpenChange={(next) => {
                if (!next) settle(false);
            }}
        >
            <AlertDialog.Portal>
                <AlertDialog.Overlay className="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm" />
                <AlertDialog.Content className="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-xl border border-border bg-card text-card-foreground shadow-xl">
                    {opts && (
                        <>
                            <div className="space-y-2 px-5 pb-4 pt-5">
                                <AlertDialog.Title className="text-base font-semibold text-foreground">
                                    {opts.title}
                                </AlertDialog.Title>
                                {opts.description && (
                                    <AlertDialog.Description asChild>
                                        <div className="line-clamp-3 break-words text-sm leading-relaxed text-muted-foreground">
                                            {opts.description}
                                        </div>
                                    </AlertDialog.Description>
                                )}
                            </div>
                            <div className="flex flex-col-reverse gap-2 border-t border-border px-5 py-3 sm:flex-row sm:justify-end">
                                <AlertDialog.Cancel className="inline-flex min-h-11 items-center justify-center rounded-md bg-secondary px-5 text-sm font-medium text-secondary-foreground transition hover:bg-secondary/80 active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                                    {opts.cancelLabel ?? 'Cancel'}
                                </AlertDialog.Cancel>
                                <AlertDialog.Action
                                    onClick={() => settle(true)}
                                    className={`inline-flex min-h-11 items-center justify-center rounded-md px-5 text-sm font-medium transition active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ${
                                        opts.danger
                                            ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90'
                                            : 'bg-primary text-primary-foreground hover:bg-primary/90'
                                    }`}
                                >
                                    {opts.confirmLabel ?? 'OK'}
                                </AlertDialog.Action>
                            </div>
                        </>
                    )}
                </AlertDialog.Content>
            </AlertDialog.Portal>
        </AlertDialog.Root>
    );
}
