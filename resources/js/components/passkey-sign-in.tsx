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
    // Arming the picker reports its own failure through the callback a refused ceremony uses.
    const acted = useRef(false);
    // The client refuses to arm without a field whose autocomplete ends in `webauthn`, and says so in
    // a message meant for whoever wrote the page.
    const [anchored, setAnchored] = useState(false);
    useEffect(() => {
        setAnchored(document.querySelector('input[autocomplete$="webauthn"]') !== null);

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
        autofill: anchored,
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
                    // Set here too: a click can arrive from a voice command with no pointer or key.
                    acted.current = true;
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
