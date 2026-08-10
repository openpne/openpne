import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, type ChangeEvent, type ComponentProps, type KeyboardEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import {
    applyEdit,
    applyPick,
    detectTrigger,
    keyAction,
    MAX_MENTIONS,
    offeredCandidates,
    type DraftMention,
    type MentionResults,
    type MentionTrigger,
} from '@/lib/mention-draft';
import { cn } from '@/lib/utils';

/**
 * A body field whose `@` opens a member picker. The draft it maintains alongside the text is the
 * only source of mentions: nothing here or on the server parses a body for `@name`
 * (docs/internals/timeline.md), so a handle typed by hand is text and stays text.
 *
 * The popup is an absolutely positioned child, not a portal: below lg these forms render inside the
 * compose sheet, whose column carries a transform, and a fixed or portalled layer would resolve
 * against the wrong box (compose-sheet-action.tsx).
 *
 * The field itself is form-agnostic — it reports the text and the draft, and the page decides what
 * to submit.
 */

/** A member the picker may offer (MemberRefSerializer::ref). */
interface Candidate {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
}

/** Long enough that typing a name is one search, short enough to feel like the list is following. */
const SEARCH_DEBOUNCE_MS = 200;

type Props = Omit<ComponentProps<'textarea'>, 'value' | 'onChange'> & {
    value: string;
    onChange: (value: string) => void;
    mentions: DraftMention[];
    onMentionsChange: (mentions: DraftMention[]) => void;
};

export function MentionTextarea({ value, onChange, mentions, onMentionsChange, ...props }: Props) {
    const t = useT();
    const listId = useId();
    const field = useRef<HTMLTextAreaElement>(null);
    // An IME is converting: the half-formed reading under the caret is not a search term (and see
    // keyAction for the keys).
    const composing = useRef(false);
    // Where the caret belongs once a pick has been rendered; the browser would otherwise leave it at
    // the end of the rewritten value.
    const caret = useRef<number | null>(null);

    const [trigger, setTrigger] = useState<MentionTrigger | null>(null);
    // Kept with the query it answers, so a search the field has already typed past shows nothing and
    // confirms nothing while the next one is still out (offeredCandidates).
    const [results, setResults] = useState<MentionResults<Candidate> | null>(null);
    const [active, setActive] = useState(0);
    // Esc gives up on the trigger the caret is in, not on the picker: detecting no trigger at all
    // ends the refusal, so deleting the "@" and typing it again offers the list once more.
    const [dismissed, setDismissed] = useState(false);

    const query = trigger !== null && !dismissed && mentions.length < MAX_MENTIONS ? trigger.query : null;
    const candidates = offeredCandidates(query, results);
    const open = candidates.length > 0;

    useEffect(() => {
        if (query === null) {
            setResults(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(`/timeline/mention-candidates?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
                .then((body: { candidates?: Candidate[] }) => {
                    setResults({ query, items: body.candidates ?? [] });
                    setActive(0);
                })
                .catch(() => {
                    // A refused or failed search closes the picker and says nothing: the member is
                    // writing a message, and an error about a decoration would interrupt that.
                    if (!controller.signal.aborted) {
                        setResults({ query, items: [] });
                    }
                });
        }, SEARCH_DEBOUNCE_MS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    useLayoutEffect(() => {
        const at = caret.current;
        if (at === null) {
            return;
        }
        caret.current = null;
        field.current?.focus();
        field.current?.setSelectionRange(at, at);
    });

    const close = useCallback(() => {
        setTrigger(null);
        setDismissed(false);
    }, []);

    const sync = useCallback((element: HTMLTextAreaElement) => {
        if (composing.current) {
            return;
        }
        const next = detectTrigger(element.value, element.selectionStart);
        setTrigger(next);
        if (next === null) {
            setDismissed(false);
        }
    }, []);

    const handleChange = (event: ChangeEvent<HTMLTextAreaElement>) => {
        const next = event.target.value;
        const carried = applyEdit(mentions, value, next);
        if (carried !== mentions) {
            onMentionsChange(carried);
        }
        onChange(next);
        sync(event.target);
    };

    const pick = (candidate: Candidate | undefined) => {
        if (candidate === undefined || trigger === null) {
            return;
        }
        const result = applyPick(mentions, value, trigger, candidate);
        onChange(result.value);
        onMentionsChange(result.mentions);
        caret.current = result.caret;
        close();
    };

    const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        // Both flags: `isComposing` is unset on the keydown that follows a commit in some browsers,
        // while the ref spans the whole compositionstart–end window.
        const action = keyAction(event.key, { open, composing: composing.current || event.nativeEvent.isComposing });
        if (action === null) {
            return;
        }
        event.preventDefault();
        if (action === 'next' || action === 'previous') {
            const step = action === 'next' ? 1 : candidates.length - 1;
            setActive((index) => (index + step) % candidates.length);
        } else if (action === 'confirm') {
            pick(candidates[active]);
        } else {
            setDismissed(true);
        }
    };

    return (
        <div className="relative">
            <Textarea
                {...props}
                ref={field}
                value={value}
                onChange={handleChange}
                onKeyDown={handleKeyDown}
                onSelect={(event) => sync(event.currentTarget)}
                onBlur={close}
                onCompositionStart={() => {
                    composing.current = true;
                }}
                onCompositionEnd={(event) => {
                    composing.current = false;
                    sync(event.currentTarget);
                }}
                // A textbox with a popup, not a combobox: ARIA-in-HTML permits no role on <textarea>,
                // and taking `combobox` would trade this field's multiline semantics for an
                // expanded state that aria-activedescendant already conveys as it moves.
                aria-haspopup="listbox"
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={open ? `${listId}-${active}` : undefined}
            />
            {/* Rendered even while closed so `aria-controls` always names a real element. Uncapped:
                the endpoint answers with at most MentionCandidates::LIMIT one-line rows, so a
                max-height would only add a scroll region no keyboard can reach. */}
            <ul
                id={listId}
                role="listbox"
                aria-label={t('Mention candidates')}
                hidden={!open}
                className="absolute inset-x-0 top-full z-20 mt-1 rounded-xl border border-border bg-card py-1 shadow-lg"
            >
                {candidates.map((candidate, index) => (
                    <li
                        key={candidate.id}
                        id={`${listId}-${index}`}
                        role="option"
                        aria-selected={index === active}
                        // Keep the caret where it is: a blur would close the list before the click landed.
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => pick(candidate)}
                        className={cn(
                            'flex min-h-11 cursor-pointer select-none items-center gap-3 px-3 text-sm text-foreground',
                            index === active && 'bg-accent text-accent-foreground',
                        )}
                    >
                        <Avatar id={candidate.id} name={candidate.name} src={candidate.imageUrl} color={candidate.avatarColor} size="sm" decorative />
                        <span className="truncate">{candidate.name}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
