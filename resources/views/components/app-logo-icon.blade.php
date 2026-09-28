{{-- Club logo; callers' fill/text classes are irrelevant for a bitmap and only sizing matters. --}}
<img src="{{ asset('images/cccc-logo.png') }}" alt="{{ config('app.name') }}" {{ $attributes->only('class') }} />
