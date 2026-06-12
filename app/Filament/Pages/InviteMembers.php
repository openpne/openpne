<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Features\Auth\Actions\IssueRegistrationToken;
use App\Features\Auth\Actions\IssueResult;
use App\Features\Auth\RegistrationMode;
use App\Features\Auth\RegistrationTokenSource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Invite members by email (OpenPNE 3 admin batch invite). Each address is issued an admin-invite
 * registration token and mailed the link, which the registrant completes like any invited
 * registration (no inviter, so no auto-friend). Reachable unless registration is suspended — an admin
 * may invite even in admin_only mode, where members cannot — and the issuance is gated again on send.
 *
 * @property-read Schema $form
 */
class InviteMembers extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return RegistrationMode::current()->allowsAdminInvite();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedEnvelope;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Invite members');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Invite members');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Invite members'))
                    ->description(__('Enter one email address per line. Each receives a registration link.'))
                    ->schema([
                        Textarea::make('emails')
                            ->label(__('Email addresses'))
                            ->rows(6)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('send')
            ->footer([
                Actions::make([
                    Action::make('send')
                        ->label(__('Send invitations'))
                        ->submit('send')
                        ->keyBindings(['mod+s']),
                ]),
            ]);
    }

    public function send(IssueRegistrationToken $issue): void
    {
        // The nav already hides the page outside allowsAdminInvite, but re-check: the mode may have
        // changed since the form was opened, and a missing row must fail closed.
        abort_unless(RegistrationMode::current()->allowsAdminInvite(), 403);

        [$valid, $invalid] = $this->partitionAddresses((string) ($this->form->getState()['emails'] ?? ''));

        $sent = 0;
        $skipped = 0;
        foreach ($valid as $email) {
            $issue($email, RegistrationTokenSource::AdminInvite) === IssueResult::AlreadyMember
                ? $skipped++
                : $sent++;
        }

        Notification::make()
            ->success()
            ->title(__('Invitations processed'))
            ->body(__(':sent sent, :skipped already registered, :invalid invalid.', [
                'sent' => $sent,
                'skipped' => $skipped,
                'invalid' => count($invalid),
            ]))
            ->send();

        $this->form->fill();
    }

    /**
     * Split the textarea into unique lowercased valid addresses and the invalid lines.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function partitionAddresses(string $input): array
    {
        $valid = [];
        $invalid = [];

        foreach (preg_split('/\r\n|\r|\n/', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (Validator::make(['email' => $line], ['email' => ['email', 'max:255']])->fails()) {
                $invalid[$line] = true;

                continue;
            }
            $valid[Str::lower($line)] = true; // dedupe case-insensitively
        }

        return [array_keys($valid), array_keys($invalid)];
    }
}
