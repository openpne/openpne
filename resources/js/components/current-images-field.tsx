import { Checkbox } from '@/components/ui/checkbox';
import { useT } from '@/lib/i18n';

interface CurrentImagesFieldProps {
    images: { id: number; thumbnailUrl: string }[];
    /** The post's non-picture attachments, removable by the same `remove_images` ids. */
    files?: { id: number; name: string; sizeLabel: string }[];
    /** Ids the member has marked for removal (the `remove_images` form field). */
    removedIds: number[];
    onToggle: (id: number, removed: boolean) => void;
}

/**
 * Controlled, so the caller owns the `remove_images` payload; renders nothing when there are no
 * current attachments.
 */
export function CurrentImagesField({ images, files = [], removedIds, onToggle }: CurrentImagesFieldProps) {
    const t = useT();

    if (images.length === 0 && files.length === 0) {
        return null;
    }

    return (
        <fieldset className="space-y-2">
            <legend className="text-sm text-foreground">{t('Current attachments')}</legend>
            <ul className="flex flex-wrap gap-3">
                {images.map((image, i) => (
                    <li key={image.id} className="space-y-1 text-center">
                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded-md object-cover" />
                        <label className="flex items-center justify-center gap-1 text-sm text-foreground">
                            <Checkbox
                                aria-label={`${t('Delete')} ${t('Image')} ${i + 1}`}
                                checked={removedIds.includes(image.id)}
                                onChange={(e) => onToggle(image.id, e.target.checked)}
                            />
                            {t('Delete')}
                        </label>
                    </li>
                ))}
                {files.map((file) => (
                    <li key={file.id} className="space-y-1">
                        <span className="block max-w-48 truncate text-sm text-foreground">
                            {file.name} <span className="text-xs text-muted-foreground">{file.sizeLabel}</span>
                        </span>
                        <label className="flex items-center gap-1 text-sm text-foreground">
                            <Checkbox
                                aria-label={`${t('Delete')} ${file.name}`}
                                checked={removedIds.includes(file.id)}
                                onChange={(e) => onToggle(file.id, e.target.checked)}
                            />
                            {t('Delete')}
                        </label>
                    </li>
                ))}
            </ul>
        </fieldset>
    );
}
