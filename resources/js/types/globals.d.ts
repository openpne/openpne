import type { DetailedHTMLProps, HTMLAttributes } from 'react';
import type { PageProps as AppPageProps } from './index';

declare module '@inertiajs/core' {
    interface PageProps extends AppPageProps {}
}

// The ALTCHA custom element (registered by `import 'altcha'`); only the attributes we set are typed.
type AltchaWidgetAttributes = DetailedHTMLProps<HTMLAttributes<HTMLElement>, HTMLElement> & {
    challenge?: string;
    name?: string;
};

declare global {
    namespace React.JSX {
        interface IntrinsicElements {
            'altcha-widget': AltchaWidgetAttributes;
        }
    }
}

declare module 'react/jsx-runtime' {
    namespace JSX {
        interface IntrinsicElements {
            'altcha-widget': AltchaWidgetAttributes;
        }
    }
}
