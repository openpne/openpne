import { type FormEvent, useState } from 'react';
import { MentionTextarea } from '@/components/compose/mention-textarea';
import { ImagesField } from '@/components/images-field';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { type DraftMention, toPayload, type MentionPayloadRow } from '@/lib/mention-draft';
import { SendFailed } from './use-talk-stream';

/**
 * The write end of the conversation, held at the foot of the page (sticky, so it stays reachable
 * while the reader scrolls back through the history and still gives its space back on a short
 * conversation). Plain text, @mentions and one image.
 *
 * The draft survives a refusal — nothing is cleared until the message is actually written, and the
 * mention drafts and the picked image are kept with the body, so a retry after a rate limit or a
 * rejected file still carries everything the composer had instead of silently posting the handles as
 * plain text or dropping the attachment.
 */
export function TalkComposer({
    groupId,
    onSend,
}: {
    groupId: number;
    onSend: (body: string, mentions: MentionPayloadRow[], image: File | null) => Promise<void>;
}) {
    const t = useT();
    const [body, setBody] = useState('');
    const [mentions, setMentions] = useState<DraftMention[]>([]);
    const [images, setImages] = useState<File[]>([]);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // Keyed by field, so the image field shows the server's verdict on the file rather than the
    // composer showing one message for everything that can go wrong.
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (sending || body.trim() === '') {
            return;
        }

        setSending(true);
        setError(null);
        setFieldErrors({});
        try {
            // Converted at submit, over the value actually being sent: the draft positions a mention
            // by UTF-16 offset, and the server measures code points.
            await onSend(body, toPayload(mentions, body), images[0] ?? null);
            setBody('');
            setMentions([]);
            setImages([]);
        } catch (failure) {
            const errors = failure instanceof SendFailed ? failure.errors : {};
            setFieldErrors(errors);
            // The image field renders its own message; anything else surfaces here.
            setError(errors.body ?? (errors.image !== undefined ? null : t('Could not send. Try again.')));
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
            <ImagesField
                id="talk_image"
                label={t('Image')}
                files={images}
                onChange={setImages}
                errors={fieldErrors}
                name="image"
                max={1}
            />
            <div className="mt-2 flex items-end gap-2">
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
