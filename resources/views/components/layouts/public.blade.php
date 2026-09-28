<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <header class="border-b border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mx-auto flex max-w-5xl items-center gap-4 px-4 py-3">
                <a href="{{ route('home') }}" class="flex items-center gap-2" wire:navigate>
                    <x-app-logo />
                </a>
                <flux:spacer />
                <nav class="flex items-center gap-1 text-sm">
                    <flux:button variant="ghost" size="sm" :href="route('track')" icon="qr-code" wire:navigate>{{ __('Confirm a circuit') }}</flux:button>
                    @auth
                        <flux:button variant="ghost" size="sm" :href="route('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:button>
                    @else
                        <flux:button variant="ghost" size="sm" :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:button>
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full {{ $wide ?? false ? 'max-w-5xl' : 'max-w-2xl' }} px-4 py-8">
            {{ $slot }}
        </main>

        <footer class="mx-auto max-w-5xl px-4 pb-8 text-center text-xs text-zinc-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}
        </footer>

        @fluxScripts
    </body>
</html>
