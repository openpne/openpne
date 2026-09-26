import { clsx, type ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// tailwind-merge dedupes only classes it can place in a group, so the project's own tokens and
// utilities (app.css) are registered here; an unregistered pair such as `pt-safe-4 pt-4` would both
// survive and the stylesheet's order would decide.
const isInsetUtility = (value: string): boolean => /^(safe|offset)(-\d+(\.\d+)?)?$/.test(value);

const twMerge = extendTailwindMerge({
    extend: {
        theme: { radius: ['field', 'card', 'tile'] },
        classGroups: {
            pt: [{ pt: [isInsetUtility] }],
            pb: [{ pb: [isInsetUtility] }],
            pl: [{ pl: [isInsetUtility] }],
            pr: [{ pr: [isInsetUtility] }],
            top: [{ top: [isInsetUtility] }],
            right: [{ right: [isInsetUtility] }],
            bottom: [{ bottom: [isInsetUtility] }],
            left: [{ left: [isInsetUtility] }],
        },
    },
});

export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
