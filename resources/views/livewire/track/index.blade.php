<?php

use App\Models\CircuitLeg;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.layouts.public')] #[Title('Confirm a circuit')] class extends Component {
    #[Url(as: 'code')]
    public string $reference = '';

    public function find(): void
    {
        $this->validate(['reference' => ['required', 'string', 'max:30']]);

        $key = 'track-code:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('reference', __('Too many attempts. Please try again in :minutes minutes.', [
                'minutes' => ceil(RateLimiter::availableIn($key) / 60),
            ]));

            return;
        }

        $leg = CircuitLeg::findByReference($this->reference);

        if (! $leg) {
            RateLimiter::hit($key, 600);
            $this->addError('reference', __('This code was not found. Check the circuit number, the step number and the 4 letters/digits printed under your QR code.'));

            return;
        }

        $this->redirect(route('track.leg', ['reference' => $leg->reference(), 'via' => 'code']), navigate: true);
    }
}; ?>

<div class="space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Confirm a circuit') }}</flux:heading>
        <flux:subheading>
            {{ __('Type the code printed under the QR code in your box on the circuit form.') }}
        </flux:subheading>
    </div>

    <form wire:submit="find" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:input
            wire:model="reference"
            :label="__('Your code')"
            placeholder="5708-2-K9F4"
            class="font-mono"
            autocomplete="off"
            autocapitalize="characters"
            autofocus
            required
        />
        <flux:button type="submit" variant="primary" class="w-full">{{ __('Continue') }}</flux:button>
    </form>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <flux:heading>{{ __('Where is my code?') }}</flux:heading>
            <p class="mt-1 text-zinc-600 dark:text-zinc-400">
                {{ __('In your box on the front of the circuit form, under the square QR code. It looks like 5708-2-K9F4: circuit number, step number, then 4 letters or digits.') }}
            </p>
        </div>
        <div class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <flux:heading>{{ __('With a smartphone') }}</flux:heading>
            <p class="mt-1 text-zinc-600 dark:text-zinc-400">
                {{ __('Open your camera and point it at the QR code: the right page opens directly, no need to type anything.') }}
            </p>
        </div>
    </div>
</div>
