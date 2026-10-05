import { usePage } from '@inertiajs/react';
import { type ChangeEvent, useRef, useState } from 'react';
import { Label } from '@/components/ui/label';
import { Tip } from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';
import { acceptPicks, MAX_POST_IMAGES } from '@/lib/image-picks';
import type { ImageUploadPolicy, PageProps } from '@/types';

/**
 * What the server shipped the pickers: the `<input accept>` list its processor reads (PostImageRules
 * is the gate) and the shrink thresholds, null while the site has switched the shrink off.
 */
export function useImageUpload(): ImageUploadPolicy {
    return usePage<PageProps>().props.imageUpload;
}

/** The types in an accept list, exactly; a file's type is matched whole, never as a substring. */
function acceptedTypes(accept: string): Set<string> {
    return new Set(
        accept
            .split(',')
            .map((entry) => entry.trim())
            .filter((entry) => entry.startsWith('image/')),
    );
}

/** A WebP whose VP8X header sets the animation bit. */
async function animatedWebp(file: File): Promise<boolean> {
    if (file.type !== 'image/webp') {
        return false;
    }
    const head = new DataView(await file.slice(0, 21).arrayBuffer());

    return head.byteLength === 21 && String.fromCharCode(head.getUint8(12), head.getUint8(13), head.getUint8(14), head.getUint8(15)) === 'VP8X' && (head.getUint8(20) & 0x02) !== 0;
}

/**
 * EXIF — GPS included — does not survive the canvas, which is as much the point as the size is.
 * Returns the original when it cannot be decoded; the server validation answers those.
 */
export async function shrink(file: File, upload: ImageUploadPolicy): Promise<File> {
    const policy = upload.shrink;
    if (policy === null) {
        return file;
    }
    // A GIF stays as picked: the canvas would flatten its animation, so an oversized one fails visibly.
    if (file.type === 'image/gif') {
        return file;
    }
    try {
        // An animated WebP stays as picked only where the server keeps its frames; elsewhere the
        // server refuses the file whole, whatever its size, so it always takes the canvas.
        const animated = await animatedWebp(file);
        if (policy.keepsFrames && animated) {
            return file;
        }
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        try {
            const scale = Math.min(1, policy.maxEdge / Math.max(bitmap.width, bitmap.height));
            // A PNG the canvas would not downscale gains nothing from the trip: the canvas writes an
            // unoptimised PNG, often a larger one, so up to the upload cap it goes as picked.
            const unchanged = file.size <= (file.type === 'image/png' ? policy.maxBytes : policy.passthroughBytes);
            if (scale === 1 && unchanged && !animated && acceptedTypes(upload.accept).has(file.type)) {
                return file;
            }
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * scale));
            canvas.height = Math.max(1, Math.round(bitmap.height * scale));
            const context = canvas.getContext('2d');
            if (!context) {
                return file;
            }
            context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            // PNG keeps its alpha channel; everything else (JPEG/WebP/HEIC…) becomes JPEG.
            const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
            const blob = await new Promise<Blob | null>((resolve) =>
                canvas.toBlob(resolve, type, type === 'image/jpeg' ? policy.quality : undefined),
            );
            if (!blob) {
                return file;
            }
            const base = file.name.replace(/\.[^.]+$/, '') || 'image';
            return new File([blob], base + (type === 'image/png' ? '.png' : '.jpg'), { type });
        } finally {
            bitmap.close();
        }
    } catch {
        return file;
    }
}

/**
 * A single-file picker's handler. The raw file is set first, so a submit that races the shrink
 * sends it (answered by the server validation) rather than nothing; the shrunk one replaces it
 * only while it is still the pick.
 */
export function useShrunkPick(value: File | null, setFile: (file: File | null) => void): { pick: (e: ChangeEvent<HTMLInputElement>) => void; busy: boolean; accept: string } {
    const upload = useImageUpload();
    const [busy, setBusy] = useState(false);
    // Counted, not flagged: a second pick before the first shrink ends must not clear the hint early.
    const pending = useRef(0);
    // The form's own value as of the last render, so a shrink that outlives a reset or a re-pick
    // finds its file gone and leaves the form alone.
    const latest = useRef(value);
    latest.current = value;

    async function pick(e: ChangeEvent<HTMLInputElement>) {
        const raw = e.target.files?.[0] ?? null;
        setFile(raw);
        if (raw === null) {
            return;
        }
        pending.current += 1;
        setBusy(true);
        try {
            const shrunk = await shrink(raw, upload);
            if (latest.current === raw && shrunk !== raw) {
                setFile(shrunk);
            }
        } finally {
            pending.current -= 1;
            if (pending.current === 0) {
                setBusy(false);
            }
        }
    }

    return { pick, busy, accept: upload.accept };
}

interface ImagesFieldProps {
    id: string;
    label: string;
    files: File[];
    onChange: (files: File[]) => void;
    /** The whole Inertia error bag: per-file rules come back keyed `<name>.N`, not `<name>`. */
    errors: Record<string, string | undefined>;
    /** Error-bag key base; matches the request field name. */
    name?: string;
    /** Server-side cap (PostImages::MAX_IMAGES). */
    max?: number;
}

/**
 * Shared picker for an `images[]` upload. Owns the failure modes the bare <input type="file">
 * pattern got wrong: selections render as removable chips and the input's own value is cleared
 * on every pick (nothing stale survives a reset after posting), oversized photos are shrunk
 * client-side before submit, and server errors keyed `images` and `images.N` are both surfaced.
 */
export function ImagesField({ id, label, files, onChange, errors, name = 'images', max = MAX_POST_IMAGES }: ImagesFieldProps) {
    const t = useT();
    const upload = useImageUpload();
    const [busy, setBusy] = useState(false);
    const [clientError, setClientError] = useState<string | null>(null);
    // Mirrors the latest selection so an in-flight shrink can re-apply against removals/resets
    // that happened while it ran, instead of resurrecting them.
    const latest = useRef(files);
    latest.current = files;

    const serverError = Object.entries(errors)
        .filter(([key, message]) => message && (key === name || key.startsWith(`${name}.`)))
        .map(([, message]) => message)
        .join(' ');
    // The server verdict outranks a stale client-side note (e.g. an earlier count-cap message
    // must not mask the validation error that explains why the post was rejected).
    const error = serverError || clientError || undefined;
    const errorId = error ? `${id}-error` : undefined;

    async function pick(e: ChangeEvent<HTMLInputElement>) {
        const picked = Array.from(e.target.files ?? []);
        // The chips below are the visible selection; the input itself must never retain one.
        e.target.value = '';
        if (picked.length === 0) {
            return;
        }
        const { files: next, refused } = acceptPicks(files, picked, max);
        setClientError(refused ? t('You can attach up to :max images.', { max }) : null);
        const accepted = next.slice(files.length);
        if (accepted.length === 0) {
            return;
        }
        // The raw files enter the form state immediately, so a submit racing the shrink sends
        // the originals (answered by the now-visible server validation) rather than silently
        // dropping the selection.
        onChange(next);
        setBusy(true);
        try {
            const shrunk = new Map<File, File>();
            for (const raw of accepted) {
                shrunk.set(raw, await shrink(raw, upload));
            }
            onChange(latest.current.map((file) => shrunk.get(file) ?? file));
        } finally {
            setBusy(false);
        }
    }

    function remove(index: number) {
        setClientError(null);
        onChange(files.filter((_, i) => i !== index));
    }

    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            {files.length > 0 && (
                <ul className="space-y-1">
                    {files.map((file, index) => (
                        <li
                            key={`${file.name}-${index}`}
                            className="flex items-center gap-2 rounded-md bg-secondary px-3 py-1.5 text-sm text-secondary-foreground"
                        >
                            <span className="min-w-0 flex-1 truncate">{file.name}</span>
                            <Tip label={t('Remove :name', { name: file.name })}>
                                <button
                                    type="button"
                                    onClick={() => remove(index)}
                                    className="flex size-6 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <svg viewBox="0 0 16 16" className="size-3.5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
                                        <path d="M3 3l10 10M13 3L3 13" />
                                    </svg>
                                </button>
                            </Tip>
                        </li>
                    ))}
                </ul>
            )}
            <input
                id={id}
                type="file"
                accept={upload.accept}
                multiple={max > 1}
                disabled={busy || files.length >= max}
                aria-invalid={error ? true : undefined}
                aria-describedby={errorId}
                onChange={pick}
                className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:text-secondary-foreground hover:file:bg-secondary/80 disabled:opacity-50"
            />
            {busy && <p className="text-xs text-muted-foreground">{t('Processing images…')}</p>}
            {error && (
                <p id={errorId} role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}
