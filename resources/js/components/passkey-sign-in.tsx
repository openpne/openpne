import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { PASSKEY_ROUTES, passkeyErrorKey } from '@/lib/passkeys';

/**
 * Arms the browser's passkey picker on the page's `autocomplete="email webauthn"` field and offers
 * the explicit button; renders nothing where WebAuthn is unsupported.
 */
export function PasskeySignIn({ remember }: { remember: () => boolean }) {
    const t = useT();
    const [failure, setFailure] = useState<string | null>(null);
    // Arming the picker fetches its options at mount, and the client reports a failure there through
    // the same callback as a refused ceremony; only what follows something the member did is theirs
    // to see.
    const acted = useRef(false);
    useEffect(() => {
        const mark = () => {
            acted.current = true;
        };
        document.addEventListener('pointerdown', mark, true);
        document.addEventListener('keydown', mark, true);

        return () => {
            document.removeEventListener('pointerdown', mark, true);
            document.removeEventListener('keydown', mark, true);
        };
    }, []);
    // The hook's isLoading also covers the armed autofill wait, so the button keeps its own flag.
    const [busy, setBusy] = useState(false);
    const passkey = usePasskeyVerify({
        autofill: true,
        routes: PASSKEY_ROUTES.login,
        remember,
        onSuccess: (response) => window.location.assign(response.redirect ?? '/'),
        // Reached from the button and from the armed autofill alike; the client already swallows an
        // unsupported or dismissed picker, so what arrives here is a refusal worth showing.
        onError: (error) => {
            if (acted.current) {
                setFailure(t(passkeyErrorKey(error)));
            }
        },
    });

    if (!passkey.isSupported) {
        return null;
    }

    return (
        <div className="space-y-1">
            <Button
                type="button"
                variant="outline"
                loading={busy}
                className="w-full"
                onClick={() => {
                    setFailure(null);
                    setBusy(true);
                    void passkey.verify().finally(() => setBusy(false));
                }}
            >
                <KeyRound className="size-4" aria-hidden />
                {t('Sign in with a passkey')}
            </Button>
            {failure && (
                <p className="text-sm text-destructive" role="alert">
                    {failure}
                </p>
            )}
        </div>
    );
}
