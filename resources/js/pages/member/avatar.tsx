import { Head, useForm, usePage } from '@inertiajs/react';
import { FlashMessage } from '@/components/flash-message';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
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
                <h1 className="text-xl font-semibold text-foreground">{t('Profile image')}</h1>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}

                {avatar ? (
                    <img src={avatar.thumbnailUrl} alt={t('Profile image')} className="size-32 rounded-md object-cover" />
                ) : (
                    <p className="text-sm text-muted-foreground">{t('No profile image set.')}</p>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        upload.post('/m/member/avatar', { onSuccess: () => upload.reset() });
                    }}
                    className="space-y-3"
                >
                    <Field label={t('Choose Image')} htmlFor="avatar_image" error={upload.errors.image}>
                        <input
                            id="avatar_image"
                            type="file"
                            name="image"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            onChange={(e) => upload.setData('image', e.target.files?.[0] ?? null)}
                            required
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80"
                        />
                    </Field>
                    <Button type="submit" loading={upload.processing}>
                        {t('Upload')}
                    </Button>
                </form>

                {avatar && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            remove.delete('/m/member/avatar', { preserveScroll: true });
                        }}
                    >
                        <button
                            type="submit"
                            disabled={remove.processing}
                            className="rounded-md text-sm text-destructive outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                        >
                            {t('Remove')}
                        </button>
                    </form>
                )}
            </main>
        </>
    );
}
