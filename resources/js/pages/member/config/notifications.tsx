import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Checkbox } from '@/components/ui/checkbox';
import { RadioCardGroup } from '@/components/ui/field';
import { RadioPill } from '@/components/ui/radio-pill';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface KindRow {
    kind: string;
    caption: string;
    dependOnNot: string | null;
    web: boolean;
    mail: boolean;
}

interface Group {
    key: string;
    caption: string;
    kinds: KindRow[];
}

interface NotificationsProps extends PageProps {
    form: { groups: Group[] };
}

type Channel = 'web' | 'mail';
type TriState = 'all' | 'friends' | 'off';

const CHANNELS: Channel[] = ['web', 'mail'];

/** Notification catalog opt-ins: instant per-toggle saves; the page re-renders from server truth. */
export default function NotificationSettings() {
    const t = useT();
    const { form } = usePage<NotificationsProps>().props;
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    const channelLabel: Record<Channel, string> = {
        web: t('In-app notifications'),
        mail: t('Email notifications'),
    };

    const save = (settings: Record<string, Partial<Record<Channel, boolean>>>) => {
        setSaving(true);
        router.post(
            '/m/member/config/notifications',
            { settings },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setSaved(true);
                    window.setTimeout(() => setSaved(false), 2000);
                },
            },
        );
    };

    return (
        <SettingsSubpage title={t('Notifications')}>
            <div className="space-y-8">
                {form.groups.map((group) => {
                    // An "(x only)" variant renders with its broad kind as one three-state control
                    // per channel; everything else is a plain per-channel checkbox row.
                    const narrow = group.kinds.find((kind) => kind.dependOnNot !== null);
                    const broad = narrow ? group.kinds.find((kind) => kind.kind === narrow.dependOnNot) : undefined;
                    const singles = group.kinds.filter((kind) => kind !== narrow && kind !== broad);

                    return (
                        <section key={group.key} className="space-y-4">
                            <h2 className="border-b border-border pb-2 text-base font-semibold text-foreground">{group.caption}</h2>
                            {singles.map((kind) => (
                                <div key={kind.kind} className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                                    <span className="text-sm text-foreground">{kind.caption}</span>
                                    <span className="flex shrink-0 gap-5">
                                        {CHANNELS.map((channel) => (
                                            <label key={channel} className="flex items-center gap-1.5 text-sm text-foreground">
                                                <Checkbox
                                                    checked={kind[channel]}
                                                    disabled={saving}
                                                    onChange={(e) => save({ [kind.kind]: { [channel]: e.target.checked } })}
                                                />
                                                <span className="sr-only">{kind.caption} — </span>
                                                {channelLabel[channel]}
                                            </label>
                                        ))}
                                    </span>
                                </div>
                            ))}
                            {broad && narrow && (
                                <div className="space-y-4">
                                    {CHANNELS.map((channel) => {
                                        const state: TriState = broad[channel] ? 'all' : narrow[channel] ? 'friends' : 'off';
                                        const options: { value: TriState; label: string }[] = [
                                            { value: 'all', label: t('All members') },
                                            { value: 'friends', label: t('%Friends% only') },
                                            { value: 'off', label: t('Off') },
                                        ];
                                        return (
                                            <RadioCardGroup key={channel} legend={`${group.caption} — ${channelLabel[channel]}`}>
                                                <p aria-hidden className="pb-1.5 text-sm text-foreground">{channelLabel[channel]}</p>
                                                <div className="flex flex-wrap gap-2">
                                                    {options.map((opt) => (
                                                        <RadioPill
                                                            key={opt.value}
                                                            name={`${group.key}-${channel}`}
                                                            value={opt.value}
                                                            checked={state === opt.value}
                                                            disabled={saving}
                                                            onChange={() =>
                                                                save({
                                                                    [broad.kind]: { [channel]: opt.value === 'all' },
                                                                    [narrow.kind]: { [channel]: opt.value !== 'off' },
                                                                })
                                                            }
                                                            label={opt.label}
                                                        />
                                                    ))}
                                                </div>
                                            </RadioCardGroup>
                                        );
                                    })}
                                </div>
                            )}
                        </section>
                    );
                })}
                <p aria-live="polite" className="min-h-5 text-sm text-muted-foreground">
                    {saved ? `✓ ${t('Saved')}` : null}
                </p>
            </div>
        </SettingsSubpage>
    );
}
