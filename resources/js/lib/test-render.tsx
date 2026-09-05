import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import { TooltipProvider } from '@/components/ui/tooltip';

/**
 * Radix throws — it does not warn — when a tooltip finds no `TooltipProvider` above it, and a
 * component test renders a fragment of the tree with none. The provider is left on Radix's own
 * delays, so a test that is about the delays states them itself.
 */
export function renderWithProviders(ui: ReactElement, options?: Parameters<typeof render>[1]) {
    return render(ui, { wrapper: TooltipProvider, ...options });
}
