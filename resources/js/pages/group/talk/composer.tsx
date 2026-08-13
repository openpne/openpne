import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { SendFailed } from './use-talk-stream';

/**
 * The write end of the conversation, held at the foot of the page (sticky, so it stays reachable
 * while the reader scrolls back through the history and still gives its space back on a short
 * conversation). Plain text only in this pass: no mention picker, no attachments.
 *
 * The draft survives a refusal — the box is not cleared until the message is actually written, so a
 * rate limit or a lost connection never eats what someone typed.
 */
export function TalkComposer({ onSend }: { onSend: (body: string) => Promise<void> }) {
    const t = useT();
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (sending || body.trim() === '') {
            return;
        }

        setSending(true);
        setError(null);
        try {
            await onSend(body);
            setBody('');
        } catch (failure) {
            setError(failure instanceof SendFailed && failure.bodyError !== null ? failure.bodyError : t('Could not send. Try again.'));
        } finally {
            setSending(false);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="sticky bottom-[var(--modern-bottom-offset)] z-10 -mx-3 border-t border-border bg-background px-3 py-2 sm:-mx-4 sm:px-4"
        >
            {error !== null && (
                <p role="alert" className="pb-2 text-sm text-destructive">
                    {error}
                </p>
            )}
            <div className="flex items-end gap-2">
                {/* No HTML maxlength: it counts UTF-16 units while the server's cap counts code
                    points, so it would cut astral-heavy text off early (the timeline textarea pins
                    the same rule). The server's 422 reaches the reader through `error` above. */}
                <Textarea
                    aria-label={t('Message')}
                    placeholder={t('Write a message')}
                    rows={2}
                    value={body}
                    onChange={(event) => setBody(event.target.value)}
                    className="min-h-0 flex-1 resize-none"
                />
                <Button type="submit" loading={sending} disabled={body.trim() === ''}>
                    {t('Send')}
                </Button>
            </div>
        </form>
    );
}
