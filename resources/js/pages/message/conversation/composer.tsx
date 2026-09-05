import { ImagePlus, SendHorizontal } from 'lucide-react';
import { type ChangeEvent, type FormEvent, useEffect, useRef, useState } from 'react';
import { BLEED_EDGES } from '@/components/card';
import { useComposerEngaged } from '@/components/compose/compose-sheet-action';
import { ACCEPT, shrink } from '@/components/images-field';
import { Spinner } from '@/components/spinner';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Tip } from '@/components/ui/tooltip';
import { useAutoGrow } from '@/lib/auto-grow';
import { SendFailed } from '@/lib/chat/use-chat-stream';
import { useT } from '@/lib/i18n';
import { acceptPicks, MAX_POST_IMAGES } from '@/lib/image-picks';
import { cn } from '@/lib/utils';

/** The bag's verdict on the attachments: per-file rules come back keyed `images.N`, not `images`. */
function imageErrorIn(errors: Record<string, string>): string {
    return Object.entries(errors)
        .filter(([key, message]) => message && (key === 'images' || key.startsWith('images.')))
        .map(([, message]) => message)
        .join(' ');
}

/**
 * A direct message carries no mentions, so this is a plain field rather than talk's MentionTextarea.
 * The draft survives a refusal: nothing is cleared until the message is written, so a retry still
 * carries the body and the picked files.
 */
export function ConversationComposer({ counterpartName, onSend }: { counterpartName: string; onSend: (body: string, images: File[]) => Promise<void> }) {
    const t = useT();
    const form = useComposerEngaged();
    const [body, setBody] = useState('');
    const [images, setImages] = useState<File[]>([]);
    const [previews, setPreviews] = useState<string[]>([]);
    const [shrinking, setShrinking] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [capNote, setCapNote] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const fileInput = useRef<HTMLInputElement>(null);
    const field = useRef<HTMLTextAreaElement>(null);
    // Mirrors the selection for the shrinks running over it, and written through select() rather than
    // on render, so an await resuming before the next render still reads what is held now.
    const held = useRef<File[]>([]);

    useAutoGrow(field, body);

    const select = (next: File[]) => {
        held.current = next;
        setImages(next);
    };

    useEffect(() => {
        const urls = images.map((image) => URL.createObjectURL(image));
        setPreviews(urls);

        return () => urls.forEach((url) => URL.revokeObjectURL(url));
    }, [images]);

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
            await onSend(body, images);
            setBody('');
            // Disowns any shrink still running for the sent files, the same way remove() does.
            select([]);
            setShrinking(false);
        } catch (failure) {
            const errors = failure instanceof SendFailed ? failure.errors : {};
            setFieldErrors(errors);
            // The attachments render their own message; anything else surfaces here.
            setError(errors.body ?? (imageErrorIn(errors) === '' ? t('Could not send. Try again.') : null));
        } finally {
            setSending(false);
        }
    };

    const imageError = imageErrorIn(fieldErrors);
    // The server's verdict first: it explains why the message was refused, which a cap note does not.
    const attachmentNote = imageError || capNote;

    return (
        <form
            ref={form}
            onSubmit={submit}
            // Flush with the screen's foot, with the home-indicator strip taken as the last of its own
            // padding: stuck at that strip's height instead, the bar would have the conversation
            // scrolling through the band under it.
            className={cn(
                // The card's own edges, from the card: below lg both run to the screen, at lg both
                // come back inside the frame — they are one surface split by a border, not two.
                BLEED_EDGES,
                // The transition is for the look whose bottom bar leaves when someone writes: the var
                // jumps, but the length it computes to is what animates.
                'sticky bottom-0 z-10 border-t border-border bg-background px-3 pt-2 pb-[calc(0.5rem+var(--modern-bottom-offset))] transition-[padding-bottom] duration-200 motion-reduce:transition-none sm:px-4',
            )}
        >
            {error !== null && (
                <p role="alert" className="pb-2 text-sm text-destructive">
                    {error}
                </p>
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
                        points, so it would cut astral-heavy text off early. */}
                    <Textarea
                        ref={field}
                        aria-label={t('Message')}
                        placeholder={t('Message :name', { name: counterpartName })}
                        rows={1}
                        value={body}
                        onChange={(event) => setBody(event.target.value)}
                        // The line-height and padding add up to the 44px the buttons beside it stand
                        // at, and the radius needs its `!` because the base rounded-field outranks it
                        // by source order.
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
