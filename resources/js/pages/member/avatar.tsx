import { Head, useForm, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface AvatarImage {
    url: string; // full bytes (FilePolicy-gated)
    thumbnailUrl: string; // 180×180 square preview
}

interface AvatarProps extends PageProps {
    avatar: AvatarImage | null;
}

export default function MemberAvatar() {
    const t = useT();
    const { avatar, flash } = usePage<AvatarProps>().props;

    const upload = useForm<{ image: File | null }>({ image: null });
    const remove = useForm({});

    return (
        <>
            <Head title={t('Profile image')} />
            <main className="mx-auto max-w-2xl space-y-6 px-4 py-8">
                <h1 className="text-xl font-semibold">{t('Profile image')}</h1>

                {flash.status && <p role="status">{flash.status}</p>}

                {avatar ? (
                    <img src={avatar.thumbnailUrl} alt={t('Profile image')} className="size-32 rounded-lg object-cover" />
                ) : (
                    <p>{t('No profile image set.')}</p>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        upload.post('/m/member/avatar', { onSuccess: () => upload.reset() });
                    }}
                    className="space-y-2"
                >
                    <input
                        type="file"
                        name="image"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        onChange={(e) => upload.setData('image', e.target.files?.[0] ?? null)}
                        required
                    />
                    {upload.errors.image && <p role="alert">{upload.errors.image}</p>}
                    <button type="submit" disabled={upload.processing}>{t('Upload')}</button>
                </form>

                {avatar && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            remove.delete('/m/member/avatar', { preserveScroll: true });
                        }}
                    >
                        <button type="submit" disabled={remove.processing} className="text-red-600 hover:underline">
                            {t('Remove')}
                        </button>
                    </form>
                )}
            </main>
        </>
    );
}
