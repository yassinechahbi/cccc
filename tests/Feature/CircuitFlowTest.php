<?php

namespace Tests\Feature;

use App\Enums\CircuitStatus;
use App\Enums\CoverQuality;
use App\Models\Circuit;
use App\Models\CircuitLeg;
use App\Models\Member;
use App\Models\User;
use App\Notifications\CircuitLegUpdated;
use App\Notifications\CircuitOnItsWay;
use App\Notifications\PoorCoverReported;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CircuitFlowTest extends TestCase
{
    use RefreshDatabase;

    private function omUser(): User
    {
        return User::factory()->om()->create();
    }

    private function launch(User $om, array $memberIds, ?string $mailedAt = null): Circuit
    {
        return Circuit::launch($om->member, $memberIds, $mailedAt ?? now()->toDateString(), null, $om)->load('legs');
    }

    public function test_an_om_creates_a_circuit_with_the_number_of_members_of_their_choice(): void
    {
        $user = $this->omUser();
        $members = Member::factory()->count(3)->create();

        Volt::actingAs($user)->test('circuits.create')
            ->set('size', 3)
            ->call('add', $members[0]->id)
            ->call('add', $members[1]->id)
            ->call('add', $members[2]->id)
            ->call('move', 2, -1)
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $circuit = Circuit::with('legs')->sole();

        $this->assertSame(config('cccc.first_circuit_number'), $circuit->number);
        $this->assertSame([$members[0]->id, $members[2]->id, $members[1]->id, $user->member_id], $circuit->legs->pluck('member_id')->all());
        $this->assertTrue($circuit->legs->last()->is_return);
        $circuit->legs->each(fn ($leg) => $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}$/', $leg->code));
    }

    public function test_the_route_must_match_the_chosen_size(): void
    {
        $user = $this->omUser();

        Volt::actingAs($user)->test('circuits.create')
            ->set('size', 4)
            ->call('add', Member::factory()->create()->id)
            ->call('create')
            ->assertHasErrors('selected');

        $this->assertSame(0, Circuit::count());
    }

    public function test_a_regular_member_cannot_open_the_circuit_creation_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('circuits.create'))->assertForbidden();
    }

    public function test_the_printable_pdf_is_generated_in_a4_and_us_letter(): void
    {
        $user = $this->omUser();
        $circuit = $this->launch($user, Member::factory()->count(4)->create()->modelKeys());

        foreach (['a4', 'letter'] as $paper) {
            $response = $this->actingAs($user)->get(route('circuits.pdf', [$circuit, 'paper' => $paper]));
            $response->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    public function test_typed_references_tolerate_case_and_separators_but_not_a_wrong_code(): void
    {
        $user = $this->omUser();
        $circuit = $this->launch($user, Member::factory()->count(2)->create()->modelKeys());
        $leg = $circuit->legs->first();

        $this->assertSame($leg->id, CircuitLeg::findByReference(strtolower($circuit->number.' 1 '.$leg->code))?->id);
        $this->assertNull(CircuitLeg::findByReference($circuit->number.'-2-'.$leg->code));
        $this->assertNull(CircuitLeg::findByReference('nonsense'));

        Volt::test('track.index')->set('reference', $circuit->number.'-1-ZZZZ')->call('find')->assertHasErrors('reference');
        Volt::test('track.index')->set('reference', $leg->reference())->call('find')
            ->assertRedirect(route('track.leg', ['reference' => $leg->reference(), 'via' => 'code']));

        $this->get(route('track.leg', $circuit->number.'-1-ZZZZ'))->assertNotFound();
        $this->get($leg->trackingUrl())->assertOk()->assertSee($leg->member->name);
    }

    public function test_a_member_confirms_reception_then_mailing_without_an_account(): void
    {
        Notification::fake();

        $user = $this->omUser();
        [$first, $second] = Member::factory()->count(2)->create();
        $circuit = $this->launch($user, [$first->id, $second->id], now()->subDays(8)->toDateString());
        $leg = $circuit->legs->first();

        Volt::test('track.leg', ['reference' => $leg->reference()])
            ->set('receivedAt', now()->subDay()->toDateString())
            ->set('quality', CoverQuality::Excellent->value)
            ->set('comment', 'Beautiful stamps!')
            ->call('recordReception')
            ->assertHasNoErrors()
            ->set('mailedAt', now()->toDateString())
            ->call('recordMailing')
            ->assertHasNoErrors();

        $leg->refresh();
        $this->assertSame(now()->subDay()->toDateString(), $leg->received_at->toDateString());
        $this->assertSame(CoverQuality::Excellent, $leg->quality);
        $this->assertSame('qr', $leg->recorded_via);
        $this->assertNotNull($leg->mailed_at);
        $this->assertSame(7, $leg->daysInTransit());

        // The OM has an account: its (verified) address wins over the directory one.
        Notification::assertSentOnDemand(CircuitLegUpdated::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $user->email);
        Notification::assertSentOnDemand(CircuitOnItsWay::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $second->email);
        Notification::assertSentOnDemandTimes(PoorCoverReported::class, 0);
    }

    public function test_a_poor_rating_alerts_the_managing_director(): void
    {
        Notification::fake();

        $user = $this->omUser();
        $circuit = $this->launch($user, Member::factory()->count(2)->create()->modelKeys());

        Volt::test('track.leg', ['reference' => $circuit->legs->first()->reference()])
            ->set('quality', CoverQuality::Poor->value)
            ->call('recordReception')
            ->assertHasNoErrors();

        Notification::assertSentOnDemand(PoorCoverReported::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === config('cccc.managing_director_email'));
    }

    public function test_a_step_cannot_be_confirmed_twice_from_the_public_page(): void
    {
        $user = $this->omUser();
        $circuit = $this->launch($user, Member::factory()->count(2)->create()->modelKeys());
        $reference = $circuit->legs->first()->reference();

        Volt::test('track.leg', ['reference' => $reference])->set('quality', 3)->call('recordReception');
        Volt::test('track.leg', ['reference' => $reference])->set('quality', 1)->call('recordReception')->assertForbidden();

        $this->assertSame(CoverQuality::VeryGood, $circuit->legs->first()->fresh()->quality);
    }

    public function test_the_om_records_steps_for_offline_members_and_the_circuit_completes(): void
    {
        Notification::fake();

        $user = $this->omUser();
        $offline = Member::factory()->withoutEmail()->create();
        $circuit = $this->launch($user, [$offline->id], now()->subDays(20)->toDateString());
        [$leg, $return] = $circuit->legs;

        Volt::actingAs($user)->test('circuits.show', ['circuit' => $circuit])
            ->call('edit', $leg->id)
            ->set('receivedAt', now()->subDays(10)->toDateString())
            ->set('quality', CoverQuality::Good->value)
            ->set('mailedAt', now()->subDays(9)->toDateString())
            ->call('save')
            ->assertHasNoErrors()
            ->call('edit', $return->id)
            ->set('receivedAt', now()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('proxy', $leg->fresh()->recorded_via);
        $this->assertSame($user->id, $leg->fresh()->recorded_by);
        $this->assertSame(CircuitStatus::Completed, $circuit->fresh()->status);

        // The offline member has no e-mail; only the OM is told the cover is on its way back.
        Notification::assertSentOnDemandTimes(CircuitOnItsWay::class, 1);
    }

    public function test_a_stranger_cannot_record_steps_on_the_circuit_page(): void
    {
        $user = $this->omUser();
        $circuit = $this->launch($user, Member::factory()->count(2)->create()->modelKeys());
        $participant = User::factory()->create(['member_id' => $circuit->legs->first()->member_id]);

        Volt::actingAs($participant)->test('circuits.show', ['circuit' => $circuit])
            ->call('edit', $circuit->legs->first()->id)
            ->assertForbidden();
    }

    public function test_members_only_see_circuits_they_take_part_in(): void
    {
        $user = $this->omUser();
        $circuit = $this->launch($user, Member::factory()->count(2)->create()->modelKeys());

        $participant = User::factory()->create(['member_id' => $circuit->legs->first()->member_id]);
        $stranger = User::factory()->create(['member_id' => Member::factory()->create()->id]);

        $this->actingAs($participant)->get(route('circuits.show', $circuit))->assertOk();
        $this->actingAs($participant)->get(route('circuits.pdf', $circuit))->assertForbidden(); // printing is the OM's job
        $this->actingAs($stranger)->get(route('circuits.show', $circuit))->assertForbidden();
        $this->actingAs($stranger)->get(route('circuits.pdf', $circuit))->assertForbidden();
    }

    public function test_an_account_is_linked_to_its_member_once_the_email_is_verified(): void
    {
        $member = Member::factory()->create(['email' => 'collector@example.com']);
        $user = User::factory()->unverified()->create(['email' => 'collector@example.com']);

        $user->linkMemberByEmail();
        $this->assertNull($user->fresh()->member_id);

        $user->markEmailAsVerified();
        event(new Verified($user));

        $this->assertSame($member->id, $user->fresh()->member_id);
    }
}
