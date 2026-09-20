// Classic surface lane: the passkey ceremonies behind `[data-passkey-register]` and
// `[data-passkey-login]`, which the Blade views render with their route URLs in data attributes.
import { Passkeys } from '@laravel/passkeys';
import { passkeyErrorKey } from '@/lib/passkeys';

function report(button: HTMLElement, message: string): void {
    const target = button.parentElement?.querySelector<HTMLElement>('[data-passkey-error]');
    if (target) {
        target.textContent = message;
        target.hidden = message === '';
    }
}

/** The Blade view passes its translations as a JSON map keyed by the English source string. */
function translate(button: HTMLElement, error: unknown): string {
    const key = passkeyErrorKey(error);
    const map = button.dataset.messages ? (JSON.parse(button.dataset.messages) as Record<string, string>) : {};

    return map[key] ?? key;
}

function bindRegister(button: HTMLButtonElement): void {
    const routes = { options: button.dataset.optionsUrl ?? '', submit: button.dataset.submitUrl ?? '' };
    const nameInput = document.getElementById(button.dataset.nameInput ?? '') as HTMLInputElement | null;
    const fallbackName = button.dataset.defaultName ?? 'Passkey';

    button.addEventListener('click', async () => {
        report(button, '');
        button.disabled = true;
        try {
            const name = nameInput?.value.trim() || fallbackName;
            await Passkeys.register({ name, routes });
            window.location.reload();
        } catch (error) {
            report(button, translate(button, error));
            button.disabled = false;
        }
    });
}

function bindLogin(button: HTMLButtonElement): void {
    const routes = { options: button.dataset.optionsUrl ?? '', submit: button.dataset.submitUrl ?? '' };
    // The button's own form, not a document-wide id: a site may place the login gadget twice.
    const remember = button.closest('form')?.querySelector<HTMLInputElement>('input[name="remember"]') ?? null;

    button.addEventListener('click', async () => {
        report(button, '');
        button.disabled = true;
        try {
            const response = await Passkeys.verify({ routes, remember: () => remember?.checked ?? false });
            window.location.assign(response.redirect ?? '/');
        } catch (error) {
            report(button, translate(button, error));
            button.disabled = false;
        }
    });
}

function boot(): void {
    const supported = Passkeys.isSupported();
    // Both buttons render hidden, since a no-JS or unsupported browser must never show a dead one.
    for (const button of document.querySelectorAll<HTMLButtonElement>('[data-passkey-register]')) {
        button.hidden = !supported;
        if (supported) {
            bindRegister(button);
        }
    }
    for (const button of document.querySelectorAll<HTMLButtonElement>('[data-passkey-login]')) {
        button.hidden = !supported;
        if (supported) {
            bindLogin(button);
        }
    }
    if (!supported) {
        for (const note of document.querySelectorAll<HTMLElement>('[data-passkey-unsupported]')) {
            note.hidden = false;
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
