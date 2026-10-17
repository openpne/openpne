import { useLaravelReactI18n } from 'laravel-react-i18n';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Heading } from '@/components/ui/heading';
import { dismissHttpFailure, useHttpFailure } from '@/lib/http-exception';

type Action = 'close' | 'reload' | 'sign-in';

interface Copy {
    title: string;
    body: string;
    action: Action;
}

function describe(status: number): Copy {
    switch (status) {
        case 401:
        case 419:
            return { title: 'Your session has expired', body: 'Sign in again to continue. What you typed is still on this page.', action: 'sign-in' };
        case 403:
            return { title: 'You cannot do that', body: 'This action is not allowed for your account.', action: 'close' };
        case 404:
            return { title: 'Not Found', body: 'The page or item is no longer there.', action: 'close' };
        case 429:
            return { title: 'Too Many Requests', body: 'Wait a moment and try again.', action: 'close' };
        case 503:
            return { title: 'Under maintenance', body: 'The site is being worked on. Try again in a few minutes.', action: 'reload' };
        default:
            return { title: 'Something went wrong', body: 'The request did not go through. Try again, and if it keeps failing, reload the page.', action: 'reload' };
    }
}

/**
 * Mounted beside the Inertia app rather than inside a page: a page's own tree is replaced on
 * navigation, and the reply that fails may be the navigation itself.
 */
export function HttpErrorDialog() {
    const failure = useHttpFailure();
    const { t } = useLaravelReactI18n();
    const copy = describe(failure?.status ?? 500);

    return (
        <Dialog
            open={failure !== null}
            onOpenChange={(open) => {
                if (!open) {
                    dismissHttpFailure();
                }
            }}
        >
            <DialogContent closeLabel={t('Close')} role="alertdialog" aria-describedby="http-error-body">
                <DialogTitle asChild>
                    <Heading as="h2" variant="section">
                        {t(copy.title)}
                    </Heading>
                </DialogTitle>
                <p id="http-error-body" className="mt-2 text-sm text-muted-foreground">
                    {t(copy.body)}
                </p>
                <div className="mt-4 flex flex-wrap justify-end gap-2">
                    <Button variant="outline" onClick={dismissHttpFailure}>
                        {t('Close')}
                    </Button>
                    {copy.action === 'reload' && <Button onClick={() => window.location.reload()}>{t('Reload')}</Button>}
                    {copy.action === 'sign-in' && <Button onClick={() => window.location.assign('/login')}>{t('Sign in')}</Button>}
                </div>
            </DialogContent>
        </Dialog>
    );
}
