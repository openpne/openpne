import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { dangerActionClass } from '@/components/ui/danger-link';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * "Delete this conversation": everything it holds, off this member's screens, in one act. It sits
 * above the conversation as a small text action, where a group's talk keeps its mute — the heading
 * is the chrome's, shared by every screen in the section.
 *
 * The confirmation states what is *not* deleted. Per-side visibility is the whole model here
 * (docs/internals/direct-messages.md), so an action that reads as a retraction would be promising
 * something the store cannot do.
 */
export function DeleteConversation({ path }: { path: string }) {
    const t = useT();
    const confirm = useConfirm();
    const [deleting, setDeleting] = useState(false);

    const remove = async () => {
        if (deleting) {
            return;
        }
        const ok = await confirm({
            title: t('Delete this conversation?'),
            description: t('It disappears from your screen only. The other person keeps their copy.'),
            confirmLabel: t('Delete'),
            danger: true,
        });
        if (!ok) {
            return;
        }

        setDeleting(true);
        // The answer is the conversation list, so nothing here has to put the emptied screen back
        // together; a failure lands the member on the page they were already on.
        router.post(`${path}/delete`, {}, { onFinish: () => setDeleting(false) });
    };

    return (
        <div className="flex justify-end">
            <button
                type="button"
                onClick={remove}
                disabled={deleting}
                className={cn(dangerActionClass, 'inline-flex items-center gap-1 text-sm disabled:opacity-50')}
            >
                <Trash2 className="size-4" aria-hidden />
                {t('Delete this conversation')}
            </button>
        </div>
    );
}
