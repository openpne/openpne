<?php

namespace App\Features\Auth\Actions;

use App\Features\Auth\AddressRegisteredMeanwhile;
use App\Features\Auth\MemberCapacity;
use App\Features\Auth\MemberCapacityReached;
use App\Features\Auth\RegistrationTokenSource;
use App\Mail\Template\MailUrlMapper;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Rules\NewEmailAddress;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A known address returns AlreadyMember without issuing or mailing, so a caller that shows the same
 * screen either way reveals nothing about which addresses are registered. The email is lowercased
 * here because Fortify's lowercase_usernames runs only in Fortify's own controller, which this flow
 * bypasses.
 */
class IssueRegistrationToken
{
    public function __construct(private readonly MemberCapacity $capacity) {}

    public function __invoke(
        string $email,
        RegistrationTokenSource $source = RegistrationTokenSource::Selfservice,
        ?Member $inviter = null,
        ?string $message = null,
    ): IssueResult {
        $email = Str::lower(Str::trim($email));
        [$result, $raw] = $this->issue($email, $source, $inviter);

        if ($raw !== null) {
            Notification::route('mail', $email)->notify(
                new RegistrationLinkNotification($raw, app()->getLocale(), $source, $inviter?->name, $message),
            );
        }

        return $result;
    }

    /**
     * The link itself instead of a mail carrying it: nothing shows the address can receive mail, so
     * the caller vouches for it, which only the operator's commands may (docs/internals/security.md,
     * "Registration links").
     *
     * @param  (Closure(CarbonInterface): void)|null  $alongside  written in the transaction that writes the link, given the link's creation time, so neither is kept without the other
     */
    public function unmailedLink(string $email, RegistrationTokenSource $source, ?Closure $alongside = null): IssueResult|string
    {
        [$result, $raw] = $this->issue(Str::lower(Str::trim($email)), $source, null, $alongside);

        return $raw === null ? $result : MailUrlMapper::resolve('member/register?token='.$raw);
    }

    /**
     * @param  string  $email  already trimmed and lowercased, and one NewEmailAddress accepts (anything else throws)
     * @param  (Closure(CarbonInterface): void)|null  $alongside
     * @return array{IssueResult, ?string} the raw token only when one was issued
     */
    private function issue(string $email, RegistrationTokenSource $source, ?Member $inviter, ?Closure $alongside = null): array
    {
        if (! NewEmailAddress::accepts($email)) {
            throw new InvalidArgumentException('A registration link is only issued for an address NewEmailAddress accepts.');
        }

        // Case-insensitive so the no-op holds on any collation: members.email is lowercased on the
        // app's own creation paths, but an upgraded row can be verbatim mixed-case, and a
        // case-sensitive store (SQLite/PostgreSQL) would otherwise miss it and leak a token + mail.
        if (Member::whereRaw('lower(email) = ?', [$email])->exists()) {
            return [IssueResult::AlreadyMember, null];
        }

        // A single atomic upsert, so two concurrent first requests cannot race the unique index into a
        // 500, and source/inviter_id are overwritten on every issuance so a self re-request never
        // inherits a prior invite's provenance.
        $raw = Str::random(40);
        $write = static function () use ($email, $raw, $source, $inviter, $alongside): void {
            $createdAt = now();
            RegistrationToken::upsert(
                [[
                    'email' => $email,
                    'token' => hash('sha256', $raw),
                    'source' => $source->value,
                    'inviter_id' => $inviter?->getKey(),
                    'created_at' => $createdAt,
                ]],
                ['email'],
                ['token', 'source', 'inviter_id', 'created_at'],
            );

            // A registration holds this link's row until it commits, so the address is asked again once the upsert has the row; that member's address is this lowercased one, as the app wrote it.
            if (Member::where('email', $email)->sharedLock()->exists()) {
                throw new AddressRegisteredMeanwhile;
            }

            if ($alongside !== null) {
                $alongside($createdAt);
            }
        };

        try {
            $this->capacity->reserveToken($email, $write);
        } catch (MemberCapacityReached) {
            return [IssueResult::CapacityFull, null];
        } catch (AddressRegisteredMeanwhile) {
            return [IssueResult::AlreadyMember, null];
        }

        return [IssueResult::Issued, $raw];
    }
}
