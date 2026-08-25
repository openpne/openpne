import { ImagePlus, Reply, SendHorizontal, X } from 'lucide-react';
import { type ChangeEvent, type FormEvent, useEffect, useRef, useState } from 'react';
import { useComposerEngaged } from '@/components/compose/compose-sheet-action';
import { MentionTextarea } from '@/components/compose/mention-textarea';
import { ACCEPT, shrink } from '@/components/images-field';
import { Spinner } from '@/components/spinner';
import { Button } from '@/components/ui/button';
import { Tip } from '@/components/ui/tooltip';
import { SendFailed } from '@/lib/chat/use-chat-stream';
import { useT } from '@/lib/i18n';
import { acceptPicks, MAX_POST_IMAGES } from '@/lib/image-picks';
import { type DraftMention, toPayload, type MentionPayloadRow } from '@/lib/mention-draft';
import type { TalkMessage } from './types';

/** The bag's verdict on the attachments: per-file rules come back keyed `images.N`, not `images`. */
function imageErrorIn(errors: Record<string, string>): string {
    return Object.entries(errors)
        .filter(([key, message]) => message && (key === 'images' || key.startsWith('images.')))
        .map(([, message]) => message)
        .join(' ');
}

/** The one-line glimpse of the message being answered: its trimmed text, or that it was a picture. */
function replyPreview(message: TalkMessage, imageLabel: string): string {
    const body = message.body.trim();

    return body !== '' ? body : message.images.length > 0 ? imageLabel : '';
}

/**
 * The write end of the conversation, held at the foot of the page (sticky, so it stays reachable
 * while the reader scrolls back through the history and still gives its space back on a short
 * conversation). Plain text, @mentions and up to MAX_POST_IMAGES images.
 *
 * The bar is one line at rest and every accessory on it is an icon; the thumbnails appear as a strip
 * above the input row only while something is picked, so the idle shape never changes.
 *
 * The draft survives a refusal — nothing is cleared until the message is actually written, and the
 * mention drafts, the picked files and any staged reply are kept with the body, so a retry after a
 * rate limit or a rejected file still carries everything the composer had instead of silently
 * posting the handles as plain text, dropping the attachments, or answering nobody.
 *
 * `replyTo` is the message a new post answers; the page owns it (rows and the sheet stage it, the
 * send consumes its id). The composer only shows it and offers to take it back — clearing on a
 * successful send is the page's, since the reply id rides that send.
 */
export function TalkComposer({
    groupId,
    groupName,
    replyTo,
    onCancelReply,
    onSend,
}: {
    groupId: number;
    groupName: string;
    replyTo: TalkMessage | null;
    onCancelReply: () => void;
    onSend: (body: string, mentions: MentionPayloadRow[], images: File[]) => Promise<void>;
}) {
    const t = useT();
    const form = useComposerEngaged();
    const [body, setBody] = useState('');
    const [mentions, setMentions] = useState<DraftMention[]>([]);
    const [images, setImages] = useState<File[]>([]);
    const [previews, setPreviews] = useState<string[]>([]);
    const [shrinking, setShrinking] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // The cap's own note, cleared by anything that changes the selection. The server's verdict
    // outranks it below: a stale count message must not mask why the message was refused.
    const [capNote, setCapNote] = useState<string | null>(null);
    // Keyed by field, so the attachments show the server's verdict on the files rather than the
    // composer showing one message for everything that can go wrong.
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const fileInput = useRef<HTMLInputElement>(null);
    // Mirrors the selection for the shrinks running over it: one that lands after a removal, a send
    // or a later pick re-applies against what is held now instead of resurrecting what it started
    // from. Written through select() rather than on render, so an await resuming before the next
    // render still reads the current selection.
    const held = useRef<File[]>([]);

    const select = (next: File[]) => {
        held.current = next;
        setImages(next);
    };

    useEffect(() => {
        const urls = images.map((image) => URL.createObjectURL(image));
        setPreviews(urls);

        return () => urls.forEach((url) => URL.revokeObjectURL(url));
    }, [images]);

    // Starting or switching a reply puts the caret in the box ready to write it. The textarea keeps
    // no ref of its own (MentionTextarea holds it private), so the composer's form is the handle that
    // reaches it; preventScroll is unneeded — the composer is sticky at the foot of the screen.
    useEffect(() => {
        if (replyTo === null) {
            return;
        }

        form.current?.querySelector('textarea')?.focus();
    }, [replyTo, form]);

    const attach = async (event: ChangeEvent<HTMLInputElement>) => {
        const picked = Array.from(event.target.files ?? []);
        // The strip below is the visible selection; the input itself must never retain one.
        event.target.value = '';
        if (picked.length === 0) {
            return;
        }
        const { files: next, refused } = acceptPicks(held.current, picked, MAX_POST_IMAGES);
        setCapNote(refused ? t('You can attach up to :max images.', { max: MAX_POST_IMAGES }) : null);
        const accepted = next.slice(held.current.length);
        if (accepted.length === 0) {
            return;
        }
        // The raw files enter the state immediately, so a submit racing the shrink sends the
        // originals (answered by the now-visible server validation) rather than dropping them.
        select(next);
        setShrinking(true);
        try {
            const shrunk = new Map<File, File>();
            for (const raw of accepted) {
                shrunk.set(raw, await shrink(raw));
            }
            select(held.current.map((image) => shrunk.get(image) ?? image));
        } finally {
            setShrinking(false);
        }
    };

    const remove = (index: number) => {
        setCapNote(null);
        select(held.current.filter((_, i) => i !== index));
    };

    // A picture is a message: the bar sends words, attachments, or both — it is only idle with neither.
    const nothingToSend = body.trim() === '' && images.length === 0;

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (sending || nothingToSend) {
            return;
        }

        setSending(true);
        setError(null);
        setCapNote(null);
        setFieldErrors({});
        try {
            // Converted at submit, over the value actually being sent: the draft positions a mention
            // by UTF-16 offset, and the server measures code points.
            await onSend(body, toPayload(mentions, body), images);
            setBody('');
            setMentions([]);
            // Disowns any shrink still running for the sent files, the same way remove() does.
            select([]);
            setShrinking(false);
        } catch (failure) {
            const errors = failure instanceof SendFailed ? failure.errors : {};
            setFieldErrors(errors);
            // The attachments render their own message; anything else — a refused reply id included —
            // surfaces here, and the staging stays for the member to resend or take back.
            setError(errors.body ?? errors.reply_to_message_id ?? (imageErrorIn(errors) === '' ? t('Could not send. Try again.') : null));
        } finally {
            setSending(false);
        }
    };

    const imageError = imageErrorIn(fieldErrors);
    // The server's verdict first: it explains why the message was refused, which a cap note does not.
    const attachmentNote = imageError || capNote;

    // Who and what a staged reply answers, derived client-side from the chosen message — a withdrawn
    // author keeps the established label, a picture-only message names itself.
    const replyName = replyTo === null ? '' : (replyTo.author?.name ?? t('Withdrawn member'));
    const replyLine = replyTo === null ? '' : replyPreview(replyTo, t('Image'));

    return (
        <form
            ref={form}
            onSubmit={submit}
            // Flush with the screen's foot, with the home-indicator strip taken as the last of its own
            // padding rather than left below it: stuck at that strip's height instead, the bar would
            // have the conversation scrolling through the band under it.
            //
            // The transition is for the look whose bottom bar stands here while the room is read and
            // leaves when someone writes: the var jumps, but the length it computes to is what
            // animates, matching the bar's own 200ms. Inert elsewhere — no other look moves it.
            className="sticky bottom-0 z-10 -mx-3 border-t border-border bg-background px-3 pt-2 pb-[calc(0.5rem+var(--modern-bottom-offset))] transition-[padding-bottom] duration-200 motion-reduce:transition-none sm:-mx-4 sm:px-4"
        >
            {error !== null && (
                <p role="alert" className="pb-2 text-sm text-destructive">
                    {error}
                </p>
            )}
            {replyTo !== null && (
                // Its own bordered row above the input, next to where the image strip sits: a reply is
                // staged, not sent, and stays through a refusal until the member takes it back or resends.
                <div className="mb-2 flex items-center gap-2 rounded-lg border border-border bg-muted/40 py-1.5 pr-1.5 pl-3 text-sm">
                    <Reply className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="shrink-0">{t('Replying to :name', { name: replyName })}</span>
                    {replyLine !== '' && <span className="min-w-0 truncate text-muted-foreground">{replyLine}</span>}
                    <Tip label={t('Cancel reply')}>
                        <button
                            type="button"
                            onClick={onCancelReply}
                            className="ml-auto shrink-0 rounded p-1 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <X className="size-4" aria-hidden />
                        </button>
                    </Tip>
                </div>
            )}
            {(images.length > 0 || attachmentNote !== null) && (
                <div className="pb-2">
                    {images.length > 0 && (
                        <ul className="flex gap-2">
                            {images.map((image, index) => (
                                <li key={`${image.name}-${index}`} className="relative w-16">
                                    {/* Alt-less: the remove button beside it is what names the picture. */}
                                    {previews[index] !== undefined && (
                                        <img src={previews[index]} alt="" className="size-16 rounded-lg border border-border object-cover" />
                                    )}
                                    <Tip label={t('Remove image :number', { number: index + 1 })}>
                                        <button
                                            type="button"
                                            onClick={() => remove(index)}
                                            disabled={sending}
                                            className="absolute -top-1.5 -right-1.5 flex size-6 items-center justify-center rounded-full border border-border bg-background text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                        >
                                            <svg viewBox="0 0 16 16" className="size-3" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
                                                <path d="M3 3l10 10M13 3L3 13" />
                                            </svg>
                                        </button>
                                    </Tip>
                                </li>
                            ))}
                        </ul>
                    )}
                    {shrinking && <p className="mt-1 text-xs text-muted-foreground">{t('Processing images…')}</p>}
                    {attachmentNote !== null && (
                        <p role="alert" className="mt-1 text-xs text-destructive">
                            {attachmentNote}
                        </p>
                    )}
                </div>
            )}
            <div className="flex items-end gap-2">
                {/* The button is the whole control: the input carries no label and no tab stop of its own. */}
                <input ref={fileInput} type="file" accept={ACCEPT} multiple onChange={attach} tabIndex={-1} aria-hidden className="sr-only" />
                <Tip label={t('Attach an image')}>
                    <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => fileInput.current?.click()}
                        disabled={sending || images.length >= MAX_POST_IMAGES}
                        className="shrink-0 text-muted-foreground"
                    >
                        <ImagePlus className="size-5" aria-hidden />
                    </Button>
                </Tip>
                <div className="min-w-0 flex-1">
                    {/* No HTML maxlength: it counts UTF-16 units while the server's cap counts code
                        points, so it would cut astral-heavy text off early (the timeline textarea pins
                        the same rule). The server's 422 reaches the reader through `error` above. */}
                    <MentionTextarea
                        aria-label={t('Message')}
                        placeholder={t('Message :name', { name: groupName })}
                        rows={1}
                        autoGrow
                        // The bar stands at the foot of the screen, with nothing under it to open into.
                        popup="above"
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
                <Tip label={t('Send')}>
                    <Button type="submit" size="icon" disabled={sending || nothingToSend} aria-busy={sending} className="shrink-0">
                        {sending ? <Spinner size={5} /> : <SendHorizontal className="size-5" aria-hidden />}
                    </Button>
                </Tip>
            </div>
        </form>
    );
}
