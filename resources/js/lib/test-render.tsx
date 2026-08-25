import { render } from '@testing-library/react';
import type { ReactElement } from 'react';
import { TooltipProvider } from '@/components/ui/tooltip';

/**
 * `render` for any component that contains a {@link Tip}. Radix throws — it does not warn — when a
 * tooltip finds no `TooltipProvider` above it, and in the app there is exactly one, at the root
 * (app.tsx). A component test renders a fragment of the tree with no root, so it has to supply it.
 *
 * The provider is left on Radix's own delays: this is here to hand the tree the context it requires,
 * not to reproduce what the app feels like. A test that is about the delays should state them itself.
 */
export function renderWithProviders(ui: ReactElement, options?: Parameters<typeof render>[1]) {
    return render(ui, { wrapper: TooltipProvider, ...options });
}
