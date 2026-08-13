import { type FormEvent, useState } from 'react';
import { MentionTextarea } from '@/components/compose/mention-textarea';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { type DraftMention, toPayload, type MentionPayloadRow } from '@/lib/mention-draft';
import { SendFailed } from './use-talk-stream';

/**
 * The write end of the conversation, held at the foot of the page (sticky, so it stays reachable
 * while the reader scrolls back through the history and still gives its space back on a short
 * conversation). Plain text plus @mentions; no attachments yet.
 *
 * The draft survives a refusal — the box is not cleared until the message is actually written, and
 * the mention drafts are kept with it, so a retry after a rate limit still carries the rows the
 * picker produced instead of silently posting the handles as plain text.
 */
export function TalkComposer({
    groupId,
    onSend,
}: {
    groupId: number;
    onSend: (body: string, mentions: MentionPayloadRow[]) => Promise<void>;
}) {
    const t = useT();
    const [body, setBody] = useState('');
    const [mentions, setMentions] = useState<DraftMention[]>([]);
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
            // Converted at submit, over the value actually being sent: the draft positions a mention
            // by UTF-16 offset, and the server measures code points.
            await onSend(body, toPayload(mentions, body));
            setBody('');
            setMentions([]);
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
                <MentionTextarea
                    aria-label={t('Message')}
                    placeholder={t('Write a message')}
                    rows={2}
                    value={body}
                    onChange={setBody}
                    mentions={mentions}
                    onMentionsChange={setMentions}
                    // The room is the mentionable set, so the endpoint is the room's own.
                    candidatesUrl={`/groups/${groupId}/talk/mention-candidates`}
                    className="min-h-0 flex-1 resize-none"
                />
                <Button type="submit" loading={sending} disabled={body.trim() === ''}>
                    {t('Send')}
                </Button>
            </div>
        </form>
    );
}
