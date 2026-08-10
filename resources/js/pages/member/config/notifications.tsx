import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { SettingsSubpage } from '@/components/settings-subpage';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { RadioCardGroup } from '@/components/ui/field';
import { Heading } from '@/components/ui/heading';
import { RadioPill } from '@/components/ui/radio-pill';
import { useT } from '@/lib/i18n';
import { currentSubscription, isIosNotInstalled, permissionState, subscribeThisDevice, unsubscribeThisDevice } from '@/lib/push';
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
    pushSettings: { enabled: boolean };
}

type Channel = 'web' | 'mail';
type TriState = 'all' | 'friends' | 'off';

const CHANNELS: Channel[] = ['web', 'mail'];

/**
 * Push: a global pause switch (instant-saved like the catalog toggles) plus this-device
 * subscribe/unsubscribe. Rendered only where the site has a VAPID keypair — the `push` shared prop is
 * that switch, so nothing here re-derives whether push is available. The catalog grid is unrelated:
 * push is an extra delivery of what already reaches the in-app feed, not a per-kind channel.
 */
function PushSection() {
    const t = useT();
    const { push, pushSettings } = usePage<NotificationsProps>().props;
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [busy, setBusy] = useState(false);
    const [deviceError, setDeviceError] = useState(false);
    // Device facts come from the browser, so they are only known after mount.
    const [permission, setPermission] = useState<ReturnType<typeof permissionState>>('unsupported');
    const [subscribed, setSubscribed] = useState(false);
    const [iosGuidance, setIosGuidance] = useState(false);

    const syncDevice = useCallback(() => {
        setPermission(permissionState());
        setIosGuidance(isIosNotInstalled());
        void currentSubscription().then((sub) => setSubscribed(sub !== null));
    }, []);

    useEffect(() => {
        syncDevice();
    }, [syncDevice]);

    if (!push) {
        return null;
    }

    const savePush = (enabled: boolean) => {
        setSaving(true);
        router.post(
            '/member/config/notifications/push',
            { enabled },
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

    // `action` resolves true when the operation succeeded and device state should be re-read, false to
    // surface an error instead of a possibly-false "subscribed". finally always clears busy, so a
    // rejected action (a thrown network error from unsubscribe) can never leave the button stuck.
    const runDevice = async (action: () => Promise<boolean>) => {
        setBusy(true);
        setDeviceError(false);
        try {
            if (await action()) {
                syncDevice();
            } else {
                setDeviceError(true);
            }
        } catch {
            setDeviceError(true);
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="space-y-4">
            <Heading as="h2" variant="section" className="border-b border-border pb-2">{t('Push notifications')}</Heading>
            <label className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                <span className="text-sm text-foreground">{t('Send push notifications to my devices')}</span>
                <Checkbox checked={pushSettings.enabled} disabled={saving} onChange={(e) => savePush(e.target.checked)} />
            </label>
            <p aria-live="polite" className="min-h-5 text-sm text-muted-foreground">{saved ? `✓ ${t('Saved')}` : null}</p>

            <div className="space-y-2">
                <Heading as="h3" variant="minor">{t('This device')}</Heading>
                {iosGuidance ? (
                    <p className="text-sm text-muted-foreground">{t('To get push notifications on iPhone or iPad, add this site to your Home Screen first.')}</p>
                ) : permission === 'unsupported' ? (
                    <p className="text-sm text-muted-foreground">{t('This browser cannot receive push notifications.')}</p>
                ) : permission === 'denied' ? (
                    <p className="text-sm text-muted-foreground">
                        {t('Notifications are blocked for this site in your browser settings. Allow them there to subscribe this device.')}
                    </p>
                ) : subscribed ? (
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="text-sm text-muted-foreground">{t('This device is subscribed.')}</span>
                        <Button
                            variant="outline"
                            size="sm"
                            loading={busy}
                            onClick={() =>
                                runDevice(async () => {
                                    // Local removal is truthful regardless of the server's answer (a dead
                                    // endpoint self-expires), so this is a failure only if it throws.
                                    await unsubscribeThisDevice();
                                    return true;
                                })
                            }
                        >
                            {t('Unsubscribe this device')}
                        </Button>
                    </div>
                ) : (
                    <Button
                        size="sm"
                        loading={busy}
                        onClick={() => runDevice(async () => (await subscribeThisDevice(push.vapidPublicKey)) !== 'error')}
                    >
                        {t('Subscribe this device')}
                    </Button>
                )}
                <p aria-live="assertive" className="min-h-5 text-sm text-destructive">
                    {deviceError ? t('Something went wrong. Please try again.') : null}
                </p>
            </div>
        </section>
    );
}

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
            '/member/config/notifications',
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
                <PushSection />
                {form.groups.map((group) => {
                    // An "(x only)" variant renders with its broad kind as one three-state control
                    // per channel; everything else is a plain per-channel checkbox row.
                    const narrow = group.kinds.find((kind) => kind.dependOnNot !== null);
                    const broad = narrow ? group.kinds.find((kind) => kind.kind === narrow.dependOnNot) : undefined;
                    const singles = group.kinds.filter((kind) => kind !== narrow && kind !== broad);

                    return (
                        <section key={group.key} className="space-y-4">
                            <Heading as="h2" variant="section" className="border-b border-border pb-2">{group.caption}</Heading>
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
