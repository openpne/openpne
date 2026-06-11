import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function MemberInvite() {
    const t = useT();
    // Flashed after a send: "invitation sent" or "already has an account".
    const status = usePage<PageProps>().props.flash.status;
    const { data, setData, post, processing, errors } = useForm<Record<string, string>>({
        email: '',
        message: '',
    });

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post('/invite');
    }

    const title = t('Invite a friend');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-md space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                <p className="text-sm text-muted-foreground">
                    {t('Enter an email address to send a registration link.')}
                </p>

                {status && <p className="text-sm font-medium text-foreground">{status}</p>}

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1">
                        <label htmlFor="email" className="block text-sm font-medium">
                            {t('Email')}
                        </label>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="off"
                            required
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                        {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor="message" className="block text-sm font-medium">
                            {t('Message (optional)')}
                        </label>
                        <textarea
                            id="message"
                            name="message"
                            rows={4}
                            value={data.message}
                            onChange={(e) => setData('message', e.target.value)}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                        {errors.message && <p className="text-sm text-destructive">{errors.message}</p>}
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    >
                        {t('Send invitation')}
                    </button>
                </form>
            </main>
        </>
    );
}
