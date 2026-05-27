import type { PageProps as AppPageProps } from './index';

declare module '@inertiajs/core' {
    interface PageProps extends AppPageProps {}
}
