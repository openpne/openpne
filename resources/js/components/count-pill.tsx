import { cn } from '@/lib/utils';

/**
 * The count badge the nav lists and the mobile bottom bar share, rendering nothing while there is
 * nothing to attend to. Not an unread marker: the same pill carries pending friend requests, which
 * are a queue rather than a read state — which is why the filled primary, not weight, is what makes
 * it stand out. Weight here would claim a meaning the pill does not have.
 *
 * **The pill never names anything by itself.** Its digits are hidden and `label`, where given, is a
 * phrase for a screen reader to read in place of them. Where that phrase ends up depends on what the
 * pill is standing in, and there are three cases:
 *
 * - **Inside a control named from its contents** (the nav entries, a bottom-bar tab, a hub tab, a
 *   group tile). The phrase joins that control's name, which is the whole point: the link says both
 *   what it is and how many are waiting. Pass `label`.
 * - **Beside a control** — a row whose link is stretched over it, with the pill in another column.
 *   The phrase would name nothing here, so the row's `<Link>` carries it as `sr-only` text instead
 *   and the pill is left to the eye. Omit `label`.
 * - **Beside a heading, with no control at all** — a section band's `right` slot. Same answer: the
 *   `<h2>` takes the phrase, the pill takes nothing. Omit `label`.
 *
 * The rule behind all three: the words belong to whatever a reader would say the count is *about*,
 * and a `<span>` with no role is never that. Naming one is prohibited by ARIA 1.2 — Chromium honours
 * it anyway, which is what let two of these cases ship announcing nothing at all.
 */
export function CountPill({ count, label, className }: { count: number; label?: string; className?: string }) {
    if (count <= 0) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] leading-none text-primary-foreground',
                className,
            )}
        >
            {/* Hidden on the digits, never on the span around them: hidden outside, the phrase below
                goes with it and the count is lost from the name with nothing to show for it. */}
            <span aria-hidden>{count > 99 ? '99+' : count}</span>
            {label !== undefined && <span className="sr-only">{label}</span>}
        </span>
    );
}
