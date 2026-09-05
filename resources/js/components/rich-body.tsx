import { UserText } from '@/components/user-text';

/**
 * `bodyHtml` is exclusively the output of the server-side sanitizer pipeline, never constructed
 * client-side, and this is the one dangerouslySetInnerHTML site. A null `bodyHtml` means a plain
 * body, which takes the same path as <UserText>.
 */
export function RichBody({ body, bodyHtml }: { body: string; bodyHtml: string | null }) {
    if (bodyHtml === null) {
        return (
            <div className="whitespace-pre-wrap break-words">
                <UserText text={body} />
            </div>
        );
    }

    return <div className="rich-body break-words" dangerouslySetInnerHTML={{ __html: bodyHtml }} />;
}
