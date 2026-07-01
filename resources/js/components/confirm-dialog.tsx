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
                <AlertDialog.Content className="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white shadow-xl dark:bg-slate-900">
                    {opts && (
                        <>
                            <div className="space-y-2 px-5 pb-4 pt-5">
                                <AlertDialog.Title className="text-base font-semibold text-slate-900 dark:text-slate-100">
                                    {opts.title}
                                </AlertDialog.Title>
                                {opts.description && (
                                    <AlertDialog.Description asChild>
                                        <div className="line-clamp-3 break-words text-sm leading-relaxed text-slate-600 dark:text-slate-300">
                                            {opts.description}
                                        </div>
                                    </AlertDialog.Description>
                                )}
                            </div>
                            <div className="flex flex-col-reverse gap-2 border-t border-slate-100 px-5 py-3 dark:border-slate-800 sm:flex-row sm:justify-end">
                                <AlertDialog.Cancel className="inline-flex min-h-11 items-center justify-center rounded-full bg-slate-100 px-5 text-sm font-medium text-slate-700 transition hover:bg-slate-200 active:scale-95 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600">
                                    {opts.cancelLabel ?? 'Cancel'}
                                </AlertDialog.Cancel>
                                <AlertDialog.Action
                                    onClick={() => settle(true)}
                                    className={`inline-flex min-h-11 items-center justify-center rounded-full px-5 text-sm font-medium text-white transition active:scale-95 ${
                                        opts.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'
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
