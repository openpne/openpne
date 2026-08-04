import { UserText } from '@/components/user-text';

/**
 * Renders a record body on the Modern surface. When the server sends bodyHtml === null the body is
 * plain and takes the exact same path as <UserText> (escape + autolink + line breaks); otherwise
 * bodyHtml is pre-rendered, already-safe HTML injected as-is.
 *
 * Invariant: bodyHtml is exclusively the output of the server-side sanitizer pipeline
 * (App\Support\BodyRenderer / App\Support\MarkdownText), never constructed client-side — the
 * pipeline is the app's sole source of trusted HTML. This is the one dangerouslySetInnerHTML site.
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
