<?php

namespace App\Models;

use App\Models\Concerns\ClearsPasswordScheme;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\AvatarColor;
use App\Support\ComposeEditor;
use App\Support\Look;
use App\Support\PreferenceKey;
use App\Support\PushDelivery;
use App\Support\Surface;
use App\Support\Visibility;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'password_scheme', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'tokens'])]
class Member extends Authenticatable
{
    /** @use HasFactory<MemberFactory> */
    use ClearsPasswordScheme, HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    // The login pipeline detects a two-factor member via class_uses_recursive, so the trait is
    // load-bearing, not decorative.
    use TwoFactorAuthenticatable;

    /**
     * A used recovery code is deleted, not silently swapped for a fresh one (the Fortify
     * default). Codes are displayed exactly once here, so the member could never see the
     * replacement — swapping would keep the unused count at a phantom "8" while the codes in
     * their safe actually dwindle. Deleting keeps the count honest; regenerating refills the set.
     *
     * @param  string  $code
     */
    public function replaceRecoveryCode($code): void
    {
        $this->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(
                array_values(array_diff($this->recoveryCodes(), [$code])),
            )),
        ])->save();

        RecoveryCodeReplaced::dispatch($this, $code);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_login_rejected' => 'boolean',
            'profile_visibility' => Visibility::class,
            'two_factor_confirmed_at' => 'datetime',
            'avatar_color' => AvatarColor::class,
        ];
    }

    /** Queue the reset mail (constant-time response) and carry the request-time locale. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token, app()->getLocale()));
    }

    /**
     * `friendships` is a bidirectional mirror: a friendship between A and B
     * is two rows (A→B and B→A). This accessor only sees rows anchored on
     * `$this`, so it relies on the mirror being maintained.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function friendships(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friendships', 'member_id', 'friend_id')
            ->withPivot('created_at');
    }

    /** @return BelongsToMany<Member, $this> */
    public function friendRequestsSent(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friend_requests', 'requester_id', 'target_id')
            ->withPivot('created_at');
    }

    /** @return BelongsToMany<Member, $this> */
    public function friendRequestsReceived(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'friend_requests', 'target_id', 'requester_id')
            ->withPivot('created_at');
    }

    /** @return BelongsToMany<Member, $this> */
    public function blocksMade(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'member_blocks', 'blocker_id', 'blocked_id')
            ->withPivot('created_at');
    }

    /** @return BelongsToMany<Member, $this> */
    public function blocksReceived(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'member_blocks', 'blocked_id', 'blocker_id')
            ->withPivot('created_at');
    }

    public function isFriendsWith(self $other): bool
    {
        return $this->friendships()->whereKey($other->getKey())->exists();
    }

    public function hasPendingRequestFrom(self $other): bool
    {
        return $this->friendRequestsReceived()->whereKey($other->getKey())->exists();
    }

    /** @return HasMany<Diary, $this> */
    public function diaries(): HasMany
    {
        return $this->hasMany(Diary::class, 'member_id');
    }

    /** @return HasMany<MemberProfile, $this> */
    public function memberProfiles(): HasMany
    {
        return $this->hasMany(MemberProfile::class, 'member_id');
    }

    /** @return HasMany<GroupMember, $this> */
    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'member_id');
    }

    /**
     * Groups this member has a pending join request to, via the group_join_requests
     * pivot (confirmed memberships are groupMemberships()).
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groupJoinRequests(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_join_requests', 'member_id', 'group_id')
            ->withPivot('created_at');
    }

    /** @return HasMany<MemberPreference, $this> */
    public function preferences(): HasMany
    {
        return $this->hasMany(MemberPreference::class, 'member_id');
    }

    /**
     * The member's Visibility value for $key, or the key default when unset. Reads the loaded
     * `preferences` relation (lazy-loaded once and cached), so repeated calls share one query.
     * Only Visibility-scaled keys go through here; the typed Surface key has its own accessor.
     */
    public function preference(PreferenceKey $key): Visibility
    {
        $value = $key->decode($this->storedPreference($key));
        assert($value instanceof Visibility);

        return $value;
    }

    /**
     * Store an explicit value for $key (even one equal to the default). Returning to
     * default-following is resetPreference(), not setPreference($default).
     */
    public function setPreference(PreferenceKey $key, Visibility $value): void
    {
        $this->writePreference($key, $value);
    }

    /** Drop any stored value for $key so reads fall back to the key default. */
    public function resetPreference(PreferenceKey $key): void
    {
        $this->preferences()->where('key', $key->value)->delete();
        $this->unsetRelation('preferences');
    }

    /**
     * The member's durable surface choice, or null when unset (an absent row means "no choice —
     * defer to SurfaceResolver's session override / mode default"). Separate from preference() so the
     * Surface value type stays type-safe at the call site.
     */
    public function preferredSurface(): ?Surface
    {
        $value = PreferenceKey::PreferredSurface->decode($this->storedPreference(PreferenceKey::PreferredSurface));
        assert($value === null || $value instanceof Surface);

        return $value;
    }

    public function setPreferredSurface(Surface $surface): void
    {
        $this->writePreference(PreferenceKey::PreferredSurface, $surface);
    }

    /** Drop the surface choice so resolution falls back to the session override / the mode's default surface. */
    public function resetPreferredSurface(): void
    {
        $this->resetPreference(PreferenceKey::PreferredSurface);
    }

    /**
     * The member's durable look choice, or null when unset (an absent row means "no choice — defer
     * to the site default"). Separate from preference() so the Look value type stays type-safe at
     * the call site. A row naming a look the site no longer offers is ignored on read (LookResolver)
     * and deleted when the administrator narrows the set.
     */
    public function preferredLook(): ?Look
    {
        $value = PreferenceKey::PreferredLook->decode($this->storedPreference(PreferenceKey::PreferredLook));
        assert($value === null || $value instanceof Look);

        return $value;
    }

    public function setPreferredLook(Look $look): void
    {
        $this->writePreference(PreferenceKey::PreferredLook, $look);
    }

    /** Drop the look choice so resolution falls back to the site default. */
    public function resetPreferredLook(): void
    {
        $this->resetPreference(PreferenceKey::PreferredLook);
    }

    /**
     * The member's compose-editor choice, or the registry default (Rich) when unset. Separate from
     * preference() so the ComposeEditor value type stays type-safe at the call site.
     */
    public function composeEditor(): ComposeEditor
    {
        $value = PreferenceKey::ComposeEditor->decode($this->storedPreference(PreferenceKey::ComposeEditor));
        assert($value instanceof ComposeEditor);

        return $value;
    }

    public function setComposeEditor(ComposeEditor $editor): void
    {
        $this->writePreference(PreferenceKey::ComposeEditor, $editor);
    }

    /**
     * Whether this member's subscribed devices are nudged by web push, or the registry default
     * (Enabled) when unset. Separate from preference() so the PushDelivery value type stays
     * type-safe at the call site.
     */
    public function pushDelivery(): PushDelivery
    {
        $value = PreferenceKey::PushDelivery->decode($this->storedPreference(PreferenceKey::PushDelivery));
        assert($value instanceof PushDelivery);

        return $value;
    }

    public function setPushDelivery(PushDelivery $delivery): void
    {
        $this->writePreference(PreferenceKey::PushDelivery, $delivery);
    }

    private function storedPreference(PreferenceKey $key): ?string
    {
        return $this->preferences->firstWhere('key', $key->value)?->value;
    }

    private function writePreference(PreferenceKey $key, Visibility|Surface|Look|ComposeEditor|PushDelivery $value): void
    {
        $this->preferences()->updateOrCreate(
            ['key' => $key->value],
            ['value' => $key->encode($value)],
        );
        $this->unsetRelation('preferences');
    }

    /** @return HasMany<MemberNotificationSetting, $this> */
    public function notificationSettings(): HasMany
    {
        return $this->hasMany(MemberNotificationSetting::class, 'member_id');
    }

    /**
     * Whether this member receives $kind on $channel. An absent row means the kind's default for
     * that channel. Reads the loaded `notificationSettings` relation (lazy-loaded once and cached),
     * so a notification's per-channel checks share one query.
     */
    public function wantsNotification(NotificationKind $kind, NotificationChannel $channel): bool
    {
        $setting = $this->notificationSettings
            ->first(fn (MemberNotificationSetting $row): bool => $row->kind === $kind->value && $row->channel === $channel->value);

        return $setting?->is_enabled ?? $kind->defaultEnabled($channel);
    }

    /**
     * Store an explicit opt-in/out for $kind on $channel, even one equal to the default — except for
     * a kind whose default is a site setting (NotificationKind::hasSiteDefault), where a row is an
     * OVERRIDE and a value equal to the current default deletes it instead. The Classic settings form
     * posts every kind on every save, so without that exception the site default would be frozen into
     * a row per member and an administrator's later flip would silently pass them by.
     */
    public function setNotificationSetting(NotificationKind $kind, NotificationChannel $channel, bool $enabled): void
    {
        $keys = ['kind' => $kind->value, 'channel' => $channel->value];

        if ($kind->hasSiteDefault() && $enabled === $kind->defaultEnabled($channel)) {
            $this->notificationSettings()->where($keys)->delete();
        } else {
            $this->notificationSettings()->updateOrCreate($keys, ['is_enabled' => $enabled]);
        }

        $this->unsetRelation('notificationSettings');
    }

    /**
     * The member's profile image (avatar), or null. One per member, enforced by the
     * member_images.member_id unique key.
     *
     * @return HasOne<MemberImage, $this>
     */
    public function avatar(): HasOne
    {
        return $this->hasOne(MemberImage::class, 'member_id');
    }

    /**
     * The member who owns this one, or null for an ordinary member. Eloquent answers null without a
     * query when the column is null, so reading it on a human costs nothing.
     *
     * @return BelongsTo<Member, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'owner_member_id');
    }

    /**
     * The AI accounts this member owns.
     *
     * @return HasMany<Member, $this>
     */
    public function aiAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'owner_member_id');
    }

    /**
     * An AI account is exactly a member with an owner. Nothing is inferred from a missing email:
     * an address-less member is an ordinary member that cannot log in, not an AI account.
     */
    public function isAiAccount(): bool
    {
        return $this->owner_member_id !== null;
    }
}
