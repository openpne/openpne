import { Head, useForm, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { InitialBadge } from '@/components/initial-badge';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { pickReadableTextColor } from '@/lib/identity-mark';
import { cn } from '@/lib/utils';
import type { AuthUser, PageProps } from '@/types';

interface AvatarImage {
    url: string; // full bytes (FilePolicy-gated)
    thumbnailUrl: string; // 180×180 square preview
}

interface BadgeColorOption {
    value: string; // the AvatarColor slug the POST expects — never post the hex
    hex: string;
    label: string; // translation key (swatch aria-label)
}

// The route is auth-gated, so narrow `auth.user` away from PageProps' nullable shape.
type AvatarProps = PageProps & {
    auth: { user: AuthUser };
    avatar: AvatarImage | null;
    badgeColor: { value: string | null; options: BadgeColorOption[] };
};

export default function MemberAvatar() {
    const t = useT();
    const { avatar, auth, badgeColor } = usePage<AvatarProps>().props;

    const upload = useForm<{ image: File | null }>({ image: null });
    const remove = useForm({});
    // Picking a swatch only updates the previews; the choice persists on the explicit save below.
    const color = useForm<{ avatar_color: string | null }>({ avatar_color: badgeColor.value });

    const tentativeHex = badgeColor.options.find((o) => o.value === color.data.avatar_color)?.hex ?? null;

    return (
        <>
            <Head title={t('Profile image')} />
            <h1 className="break-words text-xl font-semibold text-foreground">{t('Profile image')}</h1>

            <Panel bodyClassName="space-y-4">
                {avatar ? (
                    <img src={avatar.thumbnailUrl} alt={t('Profile image')} className="size-32 rounded-full object-cover" />
                ) : (
                    // Preview the badge others see, so the caption is not the only answer to "what shows now?".
                    <div className="space-y-2">
                        <InitialBadge
                            role="img"
                            aria-label={auth.user.name}
                            name={auth.user.name}
                            color={tentativeHex}
                            className="size-32 rounded-full text-4xl"
                        />
                        <p className="text-sm text-muted-foreground">{t('No profile image set.')}</p>
                    </div>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        upload.post('/member/avatar', { onSuccess: () => upload.reset() });
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
                            remove.delete('/member/avatar', { preserveScroll: true });
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

            <Panel bodyClassName="space-y-3">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        color.post('/member/avatar/color', {
                            preserveScroll: true,
                            // Rebase so the save button disarms until the member picks something new.
                            onSuccess: () => color.setDefaults(),
                        });
                    }}
                >
                    <fieldset className="space-y-3">
                        <legend className="text-base font-semibold text-foreground">{t('Badge color')}</legend>
                        <p className="text-sm text-muted-foreground">{t('Shown in place of your photo when no profile image is set.')}</p>

                        {/* The badge as others would see it with the tentative color — visible even
                            while a photo hides the page-top preview. */}
                        <InitialBadge aria-hidden name={auth.user.name} color={tentativeHex} className="size-12 rounded-full text-base" />

                        {/* The default fills the light-gray cell, so the grid is exactly 9 families ×
                            2 tiers. The options arrive family-paired (light, deep): column-major on
                            sm+ gives one column per family (light over deep); the phone's 6-column
                            row-major keeps each pair side by side. */}
                        <div className="grid w-fit grid-cols-6 gap-3 sm:grid-cols-none sm:grid-flow-col sm:grid-rows-2" role="presentation">
                            <Swatch
                                checked={color.data.avatar_color === null}
                                onSelect={() => color.setData('avatar_color', null)}
                                ariaLabel={t('None (gray)')}
                                hex={null}
                            />
                            {badgeColor.options.map((option) => (
                                <Swatch
                                    key={option.value}
                                    checked={color.data.avatar_color === option.value}
                                    onSelect={() => color.setData('avatar_color', option.value)}
                                    ariaLabel={t(option.label)}
                                    hex={option.hex}
                                />
                            ))}
                        </div>
                        {color.errors.avatar_color && <p className="text-sm text-destructive">{color.errors.avatar_color}</p>}

                        <Button type="submit" disabled={!color.isDirty} loading={color.processing}>
                            {t('Save')}
                        </Button>
                    </fieldset>
                </form>
            </Panel>
        </>
    );
}

/** One color swatch: a real radio (arrow-key group navigation) behind a colored disc; the checked
 *  state shows a checkmark so it never reads by color alone. `hex: null` is the neutral option.
 *  size-11 keeps the touch target at 44px (the grid gap alone is too thin a separator on phones). */
function Swatch({ checked, onSelect, ariaLabel, hex }: { checked: boolean; onSelect: () => void; ariaLabel: string; hex: string | null }) {
    return (
        <label
            className={cn(
                'relative inline-flex size-11 cursor-pointer items-center justify-center rounded-full',
                hex === null && 'bg-muted-foreground/20',
                checked && 'ring-2 ring-selected ring-offset-2 ring-offset-card',
                'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-card',
            )}
            style={hex ? { backgroundColor: hex } : undefined}
        >
            <input type="radio" name="avatar_color" className="sr-only" checked={checked} onChange={onSelect} aria-label={ariaLabel} />
            {checked && <Check aria-hidden className={cn('size-5', hex ? pickReadableTextColor(hex) : 'text-foreground/75')} strokeWidth={3} />}
        </label>
    );
}
