import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

/** The registration-paused notice with its request button; the surrounding frame is the page's. */
export function RegistrationPaused() {
    const t = useT();
    const [sending, setSending] = useState(false);

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setSending(true);
        router.post('/register/capacity-request', {}, { onFinish: () => setSending(false) });
    }

    return (
        <div className="space-y-4">
            <p className="text-sm text-foreground">{t('Registration is paused for now.')}</p>
            <p className="text-sm text-muted-foreground">{t('Please wait until it reopens.')}</p>
            <p className="text-sm text-muted-foreground">{t('If it is urgent, you can send a request to the administrator.')}</p>
            <form onSubmit={submit}>
                <Button type="submit" loading={sending} className="w-full">
                    {t('Send a request to the administrator')}
                </Button>
            </form>
        </div>
    );
}
