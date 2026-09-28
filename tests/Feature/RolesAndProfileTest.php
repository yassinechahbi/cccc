<?php

namespace Tests\Feature;

use App\Enums\CircuitStatus;
use App\Enums\MemberStatus;
use App\Enums\Role;
use App\Models\Circuit;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RolesAndProfileTest extends TestCase
{
    use RefreshDatabase;

    private function circuitOf(User $om, int $members = 2): Circuit
    {
        return Circuit::launch($om->member, Member::factory()->count($members)->create()->modelKeys(), now()->toDateString(), null, $om)->load('legs');
    }

    // --- Roles -------------------------------------------------------------------------

    public function test_only_admins_reach_the_users_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.users'))->assertOk();
        $this->actingAs(User::factory()->om()->create())->get(route('admin.users'))->assertForbidden();
        $this->actingAs(User::factory()->member()->create())->get(route('admin.users'))->assertForbidden();
    }

    public function test_an_admin_links_an_account_and_makes_it_an_om(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $member = Member::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $user->id)
            ->set('memberNumber', (string) $member->member_number)
            ->set('roles', [Role::OriginatingMember->value])
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame($member->id, $user->member_id);
        $this->assertTrue($user->isOm());
        $this->assertTrue($member->fresh()->is_om, 'the OM role is mirrored on the member record');

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $user->id)
            ->set('roles', [])
            ->call('save');

        $this->assertFalse($user->fresh()->isOm());
        $this->assertFalse($member->fresh()->is_om);
    }

    public function test_the_om_role_requires_a_member_number(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $user->id)
            ->set('roles', [Role::OriginatingMember->value])
            ->call('save')
            ->assertHasErrors('roles');
    }

    public function test_an_admin_cannot_remove_their_own_admin_role(): void
    {
        $admin = User::factory()->admin()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', $admin->id)
            ->set('roles', [])
            ->call('save')
            ->assertHasErrors('roles');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_a_member_cannot_be_linked_to_two_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $taken = User::factory()->member()->create();

        Volt::actingAs($admin)->test('admin.users')
            ->call('edit', User::factory()->create()->id)
            ->set('memberNumber', (string) $taken->member->member_number)
            ->call('save')
            ->assertHasErrors('memberNumber');
    }

    public function test_an_ambiguous_email_is_not_linked_automatically(): void
    {
        Member::factory()->count(2)->create(['email' => 'shared@example.com']);
        $user = User::factory()->create(['email' => 'shared@example.com']);

        $user->linkMemberByEmail();

        $this->assertNull($user->fresh()->member_id);
    }

    public function test_linking_an_om_member_grants_the_om_role(): void
    {
        $user = User::factory()->create();
        $user->linkMember(Member::factory()->om()->create());

        $this->assertTrue($user->fresh()->isOm());
    }

    // --- Circuit creation --------------------------------------------------------------

    public function test_an_om_is_always_the_originating_member_of_their_circuits(): void
    {
        $om = User::factory()->om()->create();
        $otherOm = Member::factory()->om('XYZ')->create();
        $members = Member::factory()->count(2)->create();

        $component = Volt::actingAs($om)->test('circuits.create')
            ->assertSet('omId', $om->member_id)
            ->set('omId', $otherOm->id)
            ->assertSet('omId', $om->member_id); // reverted: an OM cannot pick another OM

        $component->set('size', 2)->call('add', $members[0]->id)->call('add', $members[1]->id)->call('create')->assertHasNoErrors();

        $this->assertSame($om->member_id, Circuit::sole()->originating_member_id);
    }

    public function test_an_admin_chooses_the_originating_member(): void
    {
        $admin = User::factory()->admin()->create();
        $om = Member::factory()->om()->create();
        $members = Member::factory()->count(2)->create();

        Volt::actingAs($admin)->test('circuits.create')
            ->set('omId', $om->id)
            ->set('size', 2)
            ->call('add', $members[0]->id)
            ->call('add', $members[1]->id)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame($om->id, Circuit::sole()->originating_member_id);
    }

    public function test_a_member_without_the_om_role_cannot_create_circuits(): void
    {
        // An OM in the imported directory whose account was not given the role.
        $user = User::factory()->create(['member_id' => Member::factory()->om()->create()->id]);

        $this->actingAs($user)->get(route('circuits.create'))->assertForbidden();
    }

    // --- Visibility ----------------------------------------------------------------------

    public function test_members_see_only_the_circuits_in_progress_they_take_part_in(): void
    {
        $om = User::factory()->om()->create();
        $running = $this->circuitOf($om);
        $finished = Circuit::launch($om->member, [$running->legs->first()->member_id], now()->toDateString(), null, $om);
        $finished->update(['status' => CircuitStatus::Completed]);
        $other = $this->circuitOf(User::factory()->om('ZZZ')->create());

        $participant = User::factory()->create(['member_id' => $running->legs->first()->member_id]);

        $this->assertSame([$running->id], Circuit::visibleTo($participant)->pluck('id')->all());
        $this->actingAs($participant)->get(route('circuits.show', $finished))->assertForbidden();
        $this->actingAs($participant)->get(route('circuits.show', $other))->assertForbidden();

        // The OM keeps seeing all the circuits they originated, the admin sees everything.
        $this->assertEqualsCanonicalizing([$running->id, $finished->id], Circuit::visibleTo($om)->pluck('id')->all());
        $this->assertSame(3, Circuit::visibleTo(User::factory()->admin()->create())->count());
    }

    // --- Profile -------------------------------------------------------------------------

    public function test_a_member_updates_their_own_profile_but_not_club_fields(): void
    {
        $user = User::factory()->member()->create();
        $member = $user->member;
        $country = $member->country_id;

        Volt::actingAs($user)->test('members.profile')
            ->set('address', 'Rue des Timbres 1')
            ->set('city', '75001 Paris')
            ->set('phone', '+33 1 23 45 67 89')
            ->set('coverPreferences', 'No CTO please')
            ->set('philatelicReferences', 'Société Philatélique de Paris #123')
            // Tampering with admin-only fields must have no effect.
            ->set('status', MemberStatus::Deceased->value)
            ->set('isOm', true)
            ->call('save')
            ->assertHasNoErrors();

        $member->refresh();
        $this->assertSame('Rue des Timbres 1', $member->address);
        $this->assertSame('No CTO please', $member->cover_preferences);
        $this->assertSame('Société Philatélique de Paris #123', $member->philatelic_references);
        $this->assertSame($country, $member->country_id);
        $this->assertSame(MemberStatus::Active, $member->status);
        $this->assertFalse($member->is_om);
        $this->assertFalse($user->fresh()->isOm());
    }

    public function test_only_an_admin_edits_other_members(): void
    {
        $member = Member::factory()->create();

        $this->actingAs(User::factory()->om()->create())->get(route('members.edit', $member))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('members.edit', $member))->assertOk()->assertSee($member->name);
    }

    public function test_an_admin_sets_the_status_and_om_function_of_a_member(): void
    {
        $admin = User::factory()->admin()->create();
        $account = User::factory()->member()->create();
        $member = $account->member;

        Volt::actingAs($admin)->test('members.profile', ['member' => $member])
            ->set('status', MemberStatus::ReturnedMail->value)
            ->set('isOm', true)
            ->set('omCode', 'new')
            ->call('save')
            ->assertHasNoErrors();

        $member->refresh();
        $this->assertSame(MemberStatus::ReturnedMail, $member->status);
        $this->assertTrue($member->is_om);
        $this->assertSame('NEW', $member->om_code);
        $this->assertTrue($account->fresh()->isOm(), 'the linked account becomes an OM too');
    }

    public function test_absences_are_managed_and_shown_to_originating_members(): void
    {
        $user = User::factory()->member()->create();

        Volt::actingAs($user)->test('members.profile')
            ->set('absenceStartsOn', now()->addDays(3)->toDateString())
            ->set('absenceEndsOn', now()->addDays(20)->toDateString())
            ->set('absenceNote', 'Holidays')
            ->call('addAbsence')
            ->assertHasNoErrors()
            ->set('absenceStartsOn', now()->toDateString())
            ->set('absenceEndsOn', now()->subDay()->toDateString())
            ->call('addAbsence')
            ->assertHasErrors('absenceEndsOn');

        $member = $user->member->load('upcomingAbsences');
        $this->assertCount(1, $member->upcomingAbsences);
        $this->assertNotNull($member->absenceWithin(60));

        Volt::actingAs(User::factory()->om()->create())->test('circuits.create')
            ->set('search', (string) $member->member_number)
            ->assertSee(__('Away :from – :to', [
                'from' => now()->addDays(3)->toDateString(),
                'to' => now()->addDays(20)->toDateString(),
            ]));

        $absence = $member->upcomingAbsences->first();
        Volt::actingAs($user)->test('members.profile')->call('deleteAbsence', $absence->id);
        $this->assertModelMissing($absence);
    }

    public function test_a_member_cannot_delete_someone_elses_absence(): void
    {
        $victim = Member::factory()->create();
        $absence = $victim->absences()->create(['starts_on' => now(), 'ends_on' => now()->addWeek()]);

        Volt::actingAs(User::factory()->member()->create())->test('members.profile')->call('deleteAbsence', $absence->id);

        $this->assertModelExists($absence);
    }
}
