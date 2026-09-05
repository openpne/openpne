import { useRef, type ClipboardEvent, type KeyboardEvent } from 'react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type OtpInputProps = {
    value: string;
    onChange: (value: string) => void;
    /** Field context for each box's accessible name — per-box aria-labels override the
     * associated <label>, so the context must be baked in ("Authentication code: digit 2 of 6"). */
    label: string;
    length?: number;
    autoFocus?: boolean;
    /** Injected by Field onto the first box, so its label/error wiring lands on a real control. */
    id?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
};

/**
 * The value is one left-filled string, so a paste or an OS code autofill that drops the whole code
 * into a single box spreads across them.
 */
export function OtpInput({ value, onChange, label, length = 6, autoFocus, id, ...aria }: OtpInputProps) {
    const t = useT();
    const boxes = useRef<(HTMLInputElement | null)[]>([]);
    // write() focuses the next box before React re-renders, so the focus handler would still see
    // the previous value and bounce the focus straight back.
    const programmaticFocus = useRef(false);

    const write = (next: string) => {
        const digits = next.replace(/\D/g, '').slice(0, length);
        onChange(digits);
        programmaticFocus.current = true;
        boxes.current[Math.min(digits.length, length - 1)]?.focus();
        programmaticFocus.current = false;
    };

    const handleChange = (index: number, raw: string) => {
        // Selected-on-focus, so typing replaces this box; autofill may hand the whole code over.
        write(value.slice(0, index) + raw);
    };

    const handleKeyDown = (index: number, e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Backspace' && !value[index] && index > 0) {
            e.preventDefault();
            write(value.slice(0, index - 1));
        }
    };

    const handlePaste = (e: ClipboardEvent<HTMLInputElement>) => {
        e.preventDefault();
        write(e.clipboardData.getData('text'));
    };

    // The value is left-filled, so a click on a box past the first empty one is redirected back to it.
    const handleFocus = (index: number, input: HTMLInputElement) => {
        if (!programmaticFocus.current) {
            const active = Math.min(value.length, length - 1);
            if (index > active) {
                boxes.current[active]?.focus();
                return;
            }
        }
        input.select();
    };

    return (
        <div role="group" aria-label={label} className="flex gap-2">
            {Array.from({ length }, (_, i) => (
                <input
                    key={i}
                    ref={(el) => {
                        boxes.current[i] = el;
                    }}
                    id={i === 0 ? id : undefined}
                    type="text"
                    inputMode="numeric"
                    autoComplete={i === 0 ? 'one-time-code' : 'off'}
                    autoFocus={autoFocus && i === 0}
                    value={value[i] ?? ''}
                    onChange={(e) => handleChange(i, e.target.value)}
                    onKeyDown={(e) => handleKeyDown(i, e)}
                    onPaste={handlePaste}
                    onFocus={(e) => handleFocus(i, e.target)}
                    aria-label={`${label}: ${t('Digit :number of :count', { number: i + 1, count: length })}`}
                    aria-invalid={aria['aria-invalid']}
                    aria-describedby={i === 0 ? aria['aria-describedby'] : undefined}
                    className={cn(
                        // Mirrors the Input tokens; text-base keeps iOS Safari from zoom-locking on focus.
                        'h-12 w-10 rounded-field border border-field-border bg-field text-center text-base text-foreground shadow-sm transition-colors',
                        'focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        'aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30',
                    )}
                />
            ))}
        </div>
    );
}
