<x-layouts.public :wide="true">
    <section class="grid items-center gap-10 py-6 md:grid-cols-2">
        <div class="space-y-5">
            <img src="{{ asset('images/cccc-logo.png') }}" alt="{{ config('app.name') }}" class="size-28">
            <flux:heading size="xl" level="1" class="!text-4xl">{{ __('Real covers, from real mailboxes, all around the world.') }}</flux:heading>
            <flux:text class="text-base">
                {{ __('The Cover Collectors Circuit Club has linked stamp and cover collectors since 1947. Join a circuit: you receive a cover from another member, keep it, and send a nice cover of your own to the next member on the list.') }}
            </flux:text>
            <div class="flex flex-wrap gap-3">
                <flux:button variant="primary" icon="qr-code" :href="route('track')" wire:navigate>{{ __('I received a circuit') }}</flux:button>
                @guest
                    <flux:button :href="route('register')" wire:navigate>{{ __('Become a member') }}</flux:button>
                @endguest
            </div>
        </div>
        <x-cover-rotator />
    </section>

    <section class="mt-12 grid gap-4 md:grid-cols-3">
        @foreach ([
            ['icon' => 'document-text', 'title' => __('1. The OM prints the circuit'), 'text' => __('An Originating Member chooses 2 to 10 members and prints the circuit form, with one QR code per member.')],
            ['icon' => 'qr-code', 'title' => __('2. Each member confirms'), 'text' => __('On reception, scan your QR code or type your code on this site, rate the cover, then mail the circuit on.')],
            ['icon' => 'map', 'title' => __('3. Everyone follows'), 'text' => __('The Originating Member sees where the circuit is, and is warned if a cover takes too long.')],
        ] as $step)
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon :name="$step['icon']" class="mb-3 size-6 text-zinc-500" />
                <flux:heading>{{ $step['title'] }}</flux:heading>
                <flux:text class="mt-1">{{ $step['text'] }}</flux:text>
            </div>
        @endforeach
    </section>
</x-layouts.public>
