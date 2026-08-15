<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Models\File;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The identity an owner may rewrite on their own AI account: its name, its self-introduction and
 * its picture.
 *
 * Two contracts carry the weight here. The self-introduction is one row of a member profile, so the
 * save must touch that field and no other; and the panel offers no audience control, so the value's
 * existing audience must survive a save that never mentions it.
 */
class AiAccountIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, true);
    }

    public function test_renaming_reaches_every_surface_that_shows_the_account(): void
    {
        [$owner, $aiAccount] = $this->ownedAccount();

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => '  Research helper  '])
            ->assertRedirect(route('member.config.ai.show', ['member' => $aiAccount->getKey()]))
            ->assertSessionHas('status', __('AI account updated.'));

        // Trimmed on the way in, as at creation.
        $this->assertSame('Research helper', $aiAccount->fresh()->name);

        // Through MemberRefSerializer::ref, which is what every member reference is drawn from.
        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('account.name', 'Research helper'));
    }

    public function test_a_nameless_rename_is_refused_and_changes_nothing(): void
    {
        [$owner, $aiAccount] = $this->ownedAccount();

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Helper', $aiAccount->fresh()->name);
    }

    public function test_the_self_introduction_is_written_to_the_profile_field(): void
    {
        $field = $this->selfIntroductionField();
        [$owner, $aiAccount] = $this->ownedAccount();

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", [
            'name' => 'Helper',
            'self_introduction' => 'I answer questions about the docs.',
        ])->assertRedirect();

        $row = MemberProfile::query()->where('member_id', $aiAccount->getKey())->where('profile_id', $field->getKey())->sole();
        $this->assertSame('I answer questions about the docs.', $row->value);

        // The page offers back what was saved, so the box is not blank on the next visit — under the
        // name the operator gave the field, not a caption of this page's own.
        $field->setTranslation('en', 'About me');
        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selfIntroduction.value', 'I answer questions about the docs.')
                ->where('selfIntroduction.label', 'About me'));

        // Cleared, the row goes rather than lingering as an empty value.
        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => 'Helper', 'self_introduction' => ''])
            ->assertRedirect();
        $this->assertSame(0, MemberProfile::query()->where('member_id', $aiAccount->getKey())->count());
    }

    public function test_an_install_without_the_field_takes_the_submission_without_breaking(): void
    {
        // No op_preset_self_introduction row at all: an operator may delete it, and an upgraded site
        // may never have had one. The box is not offered, a posted value is not written, and the
        // rest of the form still saves.
        [$owner, $aiAccount] = $this->ownedAccount();

        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selfIntroduction', null));

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", [
            'name' => 'Renamed',
            'self_introduction' => 'Nowhere to put this.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $aiAccount->fresh()->name);
        $this->assertSame(0, MemberProfile::query()->where('member_id', $aiAccount->getKey())->count());
    }

    public function test_a_field_the_profile_editor_may_not_write_is_not_written_here_either(): void
    {
        // is_disp_config off is the operator saying members do not edit this on their own profile.
        // The AI panel is that same edit, so it is not a way around the answer.
        $field = $this->selfIntroductionField();
        $field->update(['is_disp_config' => false]);
        [$owner, $aiAccount] = $this->ownedAccount();

        $this->actingAs($owner)->get("/member/config/ai/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selfIntroduction', null));

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => 'Helper', 'self_introduction' => 'Sneaked in'])
            ->assertRedirect();

        $this->assertSame(0, MemberProfile::query()->where('member_id', $aiAccount->getKey())->count());
    }

    public function test_a_saved_value_keeps_the_audience_it_already_had(): void
    {
        // The panel shows no audience control and the row is replaced wholesale, so what the value
        // already carries has to be handed back — otherwise saving a typo fix would widen it.
        $field = $this->selfIntroductionField();
        [$owner, $aiAccount] = $this->ownedAccount();
        MemberProfile::factory()->create([
            'member_id' => $aiAccount->getKey(),
            'profile_id' => $field->getKey(),
            'value' => 'Old',
            'visibility' => Visibility::Private,
        ]);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => 'Helper', 'self_introduction' => 'New'])
            ->assertRedirect();

        $row = MemberProfile::query()->where('member_id', $aiAccount->getKey())->sole();
        $this->assertSame('New', $row->value);
        $this->assertSame(Visibility::Private, $row->visibility);
    }

    public function test_a_first_value_starts_at_the_fields_default_audience(): void
    {
        // A default that is neither the column default nor the fallback the save would land on if
        // the audience were simply omitted.
        $field = $this->selfIntroductionField(Visibility::Friends);
        [$owner, $aiAccount] = $this->ownedAccount();

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => 'Helper', 'self_introduction' => 'First words'])
            ->assertRedirect();

        $row = MemberProfile::query()->where('member_id', $aiAccount->getKey())->sole();
        $this->assertSame(Visibility::Friends, $row->visibility);
    }

    public function test_saving_the_panel_leaves_every_other_profile_value_alone(): void
    {
        // The panel carries one field. Saved through the whole-profile write instead of a per-field
        // one, it would take every other value the account holds down with it.
        $this->selfIntroductionField();
        $other = Profile::factory()->create(['is_disp_config' => true]);
        [$owner, $aiAccount] = $this->ownedAccount();
        MemberProfile::factory()->create([
            'member_id' => $aiAccount->getKey(),
            'profile_id' => $other->getKey(),
            'value' => 'Kept',
        ]);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => 'Helper', 'self_introduction' => 'Mine'])
            ->assertRedirect();

        $this->assertSame('Kept', MemberProfile::query()
            ->where('member_id', $aiAccount->getKey())->where('profile_id', $other->getKey())->value('value'));
    }

    public function test_the_field_rules_hold_for_this_panel_too(): void
    {
        // The same field, so the same limits: what the profile editor refuses, this refuses.
        $this->selfIntroductionField()->update(['value_max' => 10]);
        [$owner, $aiAccount] = $this->ownedAccount();

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", [
            'name' => 'Helper',
            'self_introduction' => 'Far too long to fit',
        ])->assertSessionHasErrors('self_introduction');

        $this->assertSame(0, MemberProfile::query()->where('member_id', $aiAccount->getKey())->count());
    }

    public function test_an_image_is_uploaded_replaced_and_removed(): void
    {
        [$owner, $aiAccount] = $this->ownedAccount();
        $page = route('member.config.ai.show', ['member' => $aiAccount->getKey()]);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/avatar", ['image' => UploadedFile::fake()->image('one.png', 10, 10)])
            ->assertRedirect($page)
            ->assertSessionHas('status', __('Profile image updated.'));

        $old = $aiAccount->fresh()->avatar->file;
        $this->assertSame('member', $old->related_entity_type);
        $this->assertSame($aiAccount->getKey(), $old->related_entity_id);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/avatar", ['image' => UploadedFile::fake()->image('two.png', 10, 10)])
            ->assertRedirect($page);

        // One image at a time, and the replaced one's bytes go with it.
        $this->assertSame(1, $aiAccount->fresh()->avatar()->count());
        $new = $aiAccount->fresh()->avatar->file;
        $this->assertNotSame($old->getKey(), $new->getKey());
        $this->assertNull(File::find($old->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $old->getKey())->count());

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/avatar/delete")
            ->assertRedirect($page)
            ->assertSessionHas('status', __('Profile image removed.'));

        $this->assertSame(0, $aiAccount->fresh()->avatar()->count());
        $this->assertNull(File::find($new->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $new->getKey())->count());
    }

    public function test_a_file_that_is_not_a_deliverable_image_is_refused(): void
    {
        [$owner, $aiAccount] = $this->ownedAccount();

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/avatar", ['image' => UploadedFile::fake()->createWithContent('x.svg', '<svg></svg>')])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, $aiAccount->avatar()->count());
    }

    public function test_switching_the_setting_off_leaves_the_identity_editable(): void
    {
        // AiAccountsEnabled gates creation and nothing else: an operator closing the door must not
        // strand an owner with a typo they cannot fix.
        $this->selfIntroductionField();
        [$owner, $aiAccount] = $this->ownedAccount();
        $this->setSnsSetting(SnsSettingKey::AiAccountsEnabled, false);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => 'Renamed', 'self_introduction' => 'Still mine'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $aiAccount->fresh()->name);

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}/avatar", ['image' => UploadedFile::fake()->image('me.png', 10, 10)])
            ->assertRedirect();
        $this->assertSame(1, $aiAccount->fresh()->avatar()->count());
    }

    public function test_a_human_member_cannot_be_edited_through_this_page(): void
    {
        // Ownership, not "is a member", is what these POSTs answer to — the viewer's own row
        // included.
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->post("/member/config/ai/{$viewer->getKey()}", ['name' => 'Renamed'])->assertNotFound();
        $this->assertNotSame('Renamed', $viewer->fresh()->name);
    }

    public function test_the_edits_are_throttled(): void
    {
        [$owner, $aiAccount] = $this->ownedAccount();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => "Helper {$i}"])->assertRedirect();
        }

        $this->actingAs($owner)->post("/member/config/ai/{$aiAccount->getKey()}", ['name' => 'One too many'])->assertStatus(429);
        $this->assertSame('Helper 4', $aiAccount->fresh()->name);
    }

    /** @return array{Member, Member} owner and the AI account they own */
    private function ownedAccount(): array
    {
        $owner = Member::factory()->create();

        return [$owner, Member::factory()->aiAccount($owner)->create(['name' => 'Helper'])];
    }

    /** The install's self-introduction field, as PresetProfileSeeder registers it. */
    private function selfIntroductionField(Visibility $default = Visibility::Members): Profile
    {
        return Profile::factory()->preset('self_introduction')->create([
            'form_type' => 'textarea',
            'is_edit_public_flag' => true,
            'default_visibility' => $default,
        ]);
    }
}
