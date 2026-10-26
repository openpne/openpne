import { Paperclip } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { AttachmentFile } from '@/types';

/**
 * A post's non-picture attachments as download rows. A plain anchor: the server answers with
 * `Content-Disposition: attachment`, so the browser saves without leaving the page, and `/file/` is
 * a bytes segment the router already leaves alone.
 */
export function FileChips({ files, className }: { files: AttachmentFile[]; className?: string }) {
    if (files.length === 0) {
        return null;
    }

    return (
        <ul className={cn('flex flex-col gap-1', className)}>
            {files.map((file) => (
                <li key={file.id}>
                    <a
                        href={file.url}
                        className="inline-flex max-w-full items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-1.5 text-sm text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <Paperclip aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                        <span className="truncate">{file.name}</span>
                        <span className="shrink-0 text-xs text-muted-foreground">{file.sizeLabel}</span>
                    </a>
                </li>
            ))}
        </ul>
    );
}
