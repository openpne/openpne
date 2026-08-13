import { ImagePlus, SendHorizontal } from 'lucide-react';
import { type ChangeEvent, type FormEvent, useEffect, useRef, useState } from 'react';
import { MentionTextarea } from '@/components/compose/mention-textarea';
import { ACCEPT, shrink } from '@/components/images-field';
import { Spinner } from '@/components/spinner';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { type DraftMention, toPayload, type MentionPayloadRow } from '@/lib/mention-draft';
import { SendFailed } from './use-talk-stream';

/** The bag's verdict on the attachment: per-file rules come back keyed `image.N`, not `image`. */
function imageErrorIn(errors: Record<string, string>): string {
    return Object.entries(errors)
        .filter(([key, message]) => message && (key === 'image' || key.startsWith('image.')))
        .map(([, message]) => message)
        .join(' ');
}

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
    groupName,
    onSend,
}: {
    groupId: number;
    groupName: string;
    onSend: (body: string, mentions: MentionPayloadRow[], image: File | null) => Promise<void>;
}) {
    const t = useT();
    const [body, setBody] = useState('');
    const [mentions, setMentions] = useState<DraftMention[]>([]);
    const [image, setImage] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [shrinking, setShrinking] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // Keyed by field, so the attachment shows the server's verdict on the file rather than the
    // composer showing one message for everything that can go wrong.
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const fileInput = useRef<HTMLInputElement>(null);
    // Names the pick a running shrink belongs to, so one that lands after a removal or a later pick
    // does not resurrect its file.
    const picked = useRef<File | null>(null);

    useEffect(() => {
        if (image === null) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(image);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [image]);

    const attach = async (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        // The thumbnail below is the visible selection; the input itself must never retain one.
        event.target.value = '';
        if (file === undefined) {
            return;
        }
        // The raw file enters the state immediately, so a submit racing the shrink sends the
        // original (answered by the now-visible server validation) rather than dropping it.
        picked.current = file;
        setImage(file);
        setShrinking(true);
        try {
            const shrunk = await shrink(file);
            if (picked.current === file) {
                setImage(shrunk);
            }
        } finally {
            if (picked.current === file) {
                setShrinking(false);
            }
        }
    };

    const remove = () => {
        // Gives up on a shrink still running for it as well; the next pick re-arms both.
        picked.current = null;
        setImage(null);
        setShrinking(false);
    };

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
            await onSend(body, toPayload(mentions, body), image);
            setBody('');
            setMentions([]);
            // Disowns a shrink still running for the sent file, the same way remove() does.
            picked.current = null;
            setImage(null);
            setShrinking(false);
        } catch (failure) {
            const errors = failure instanceof SendFailed ? failure.errors : {};
            setFieldErrors(errors);
            // The attachment renders its own message; anything else surfaces here.
            setError(errors.body ?? (imageErrorIn(errors) === '' ? t('Could not send. Try again.') : null));
        } finally {
            setSending(false);
        }
    };

    const imageError = imageErrorIn(fieldErrors);

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
            {image !== null && (
                <div className="pb-2">
                    <div className="relative w-16">
                        {/* Alt-less: the remove button beside it is what names the file. */}
                        {preview !== null && <img src={preview} alt="" className="size-16 rounded-lg border border-border object-cover" />}
                        <button
                            type="button"
                            onClick={remove}
                            aria-label={t('Remove :name', { name: image.name })}
                            className="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full border border-border bg-background text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <svg viewBox="0 0 16 16" className="size-3" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
                                <path d="M3 3l10 10M13 3L3 13" />
                            </svg>
                        </button>
                    </div>
                    {shrinking && <p className="mt-1 text-xs text-muted-foreground">{t('Processing images…')}</p>}
                    {imageError !== '' && (
                        <p role="alert" className="mt-1 text-xs text-destructive">
                            {imageError}
                        </p>
                    )}
                </div>
            )}
            <div className="flex items-end gap-2">
                {/* The button is the whole control: the input carries no label and no tab stop of its own. */}
                <input ref={fileInput} type="file" accept={ACCEPT} onChange={attach} tabIndex={-1} aria-hidden className="sr-only" />
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => fileInput.current?.click()}
                    disabled={image !== null}
                    aria-label={t('Attach an image')}
                    className="shrink-0 text-muted-foreground"
                >
                    <ImagePlus className="size-5" aria-hidden />
                </Button>
                <div className="min-w-0 flex-1">
                    {/* No HTML maxlength: it counts UTF-16 units while the server's cap counts code
                        points, so it would cut astral-heavy text off early (the timeline textarea pins
                        the same rule). The server's 422 reaches the reader through `error` above. */}
                    <MentionTextarea
                        aria-label={t('Message')}
                        placeholder={t('Message :name', { name: groupName })}
                        rows={1}
                        autoGrow
                        value={body}
                        onChange={setBody}
                        mentions={mentions}
                        onMentionsChange={setMentions}
                        // The room is the mentionable set, so the endpoint is the room's own.
                        candidatesUrl={`/groups/${groupId}/talk/mention-candidates`}
                        // The stated line-height and padding add up to the 44px the buttons beside it
                        // stand at; past five lines the box scrolls. The radius needs its `!`: the
                        // base rounded-field outranks it by source order, whatever this list says.
                        // The placeholder must hold to one line: wrapped, it would either inflate the
                        // idle bar or peek out clipped under the empty box's fixed height.
                        className="max-h-40 min-h-11 resize-none overflow-y-auto rounded-2xl! py-[9px] leading-6 placeholder:overflow-hidden placeholder:text-ellipsis placeholder:whitespace-nowrap"
                    />
                </div>
                <Button type="submit" size="icon" disabled={sending || body.trim() === ''} aria-busy={sending} aria-label={t('Send')} className="shrink-0">
                    {sending ? <Spinner size={5} /> : <SendHorizontal className="size-5" aria-hidden />}
                </Button>
            </div>
        </form>
    );
}
