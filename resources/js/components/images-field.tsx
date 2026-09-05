import { type ChangeEvent, useRef, useState } from 'react';
import { Label } from '@/components/ui/label';
import { Tip } from '@/components/ui/tooltip';
import { useT } from '@/lib/i18n';
import { acceptPicks, MAX_POST_IMAGES } from '@/lib/image-picks';

/**
 * Server contract (PostImageRules): raster only, 5MB / 5000px per file — shrunk output sits far
 * under both.
 */
export const ACCEPT = 'image/jpeg,image/png,image/gif,image/webp';
const MAX_EDGE = 2048;
const JPEG_QUALITY = 0.82;
/** Small-enough originals are submitted as picked, avoiding a pointless re-encode. */
const PASSTHROUGH_BYTES = 2 * 1024 * 1024;

/**
 * EXIF — GPS included — does not survive the canvas, which is as much the point as the size is.
 * Returns the original when it cannot be decoded; the server validation answers those.
 */
export async function shrink(file: File): Promise<File> {
    // A GIF stays as picked: the canvas would flatten its animation, so an oversized one fails visibly.
    if (file.type === 'image/gif') {
        return file;
    }
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        try {
            const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
            if (scale === 1 && file.size <= PASSTHROUGH_BYTES && ACCEPT.includes(file.type)) {
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
                canvas.toBlob(resolve, type, type === 'image/jpeg' ? JPEG_QUALITY : undefined),
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
                shrunk.set(raw, await shrink(raw));
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
                accept={ACCEPT}
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
