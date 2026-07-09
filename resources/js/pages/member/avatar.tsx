import { Head, useForm, usePage } from '@inertiajs/react';
import { InitialBadge } from '@/components/initial-badge';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { AuthUser, PageProps } from '@/types';

interface AvatarImage {
    url: string; // full bytes (FilePolicy-gated)
    thumbnailUrl: string; // 180×180 square preview
}

// The route is auth-gated, so narrow `auth.user` away from PageProps' nullable shape.
type AvatarProps = PageProps & {
    auth: { user: AuthUser };
    avatar: AvatarImage | null;
};

export default function MemberAvatar() {
    const t = useT();
    const { avatar, auth } = usePage<AvatarProps>().props;

    const upload = useForm<{ image: File | null }>({ image: null });
    const remove = useForm({});

    return (
        <>
            <Head title={t('Profile image')} />
            <h1 className="break-words text-xl font-semibold text-foreground">{t('Profile image')}</h1>

            <Panel bodyClassName="space-y-4">
                {avatar ? (
                    <img src={avatar.thumbnailUrl} alt={t('Profile image')} className="size-32 rounded-md object-cover" />
                ) : (
                    // Preview the badge others see, so the caption is not the only answer to "what shows now?".
                    <div className="space-y-2">
                        <InitialBadge role="img" aria-label={auth.user.name} name={auth.user.name} className="size-32 rounded-md text-4xl" />
                        <p className="text-sm text-muted-foreground">{t('No profile image set.')}</p>
                    </div>
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
            </Panel>
        </>
    );
}
