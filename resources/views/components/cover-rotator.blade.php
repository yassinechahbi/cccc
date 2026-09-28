@php
    // Every image dropped in public/images/covers is part of the rotation.
    $covers = collect(glob(public_path('images/covers/*')) ?: [])
        ->filter(fn (string $path) => in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true))
        ->map(fn (string $path) => asset('images/covers/'.basename($path)))
        ->shuffle()
        ->values();
@endphp

@if ($covers->isNotEmpty())
    <div id="cover-rotator" class="grid grid-cols-2 gap-3" data-covers='@json($covers)'>
        @foreach ($covers->take(2) as $index => $cover)
            {{-- Fixed-ratio frame: covers of any shape rotate without the page jumping. --}}
            <div class="flex aspect-[10/7] items-center justify-center {{ $index === 1 ? 'mt-8' : '' }}">
                <img src="{{ $cover }}" alt="{{ __('Example of a circuit cover') }}"
                    class="max-h-full max-w-full rounded-lg shadow-md transition-opacity duration-500">
            </div>
        @endforeach
    </div>

    <script>
        (function () {
            const root = document.getElementById('cover-rotator');
            const covers = JSON.parse(root.dataset.covers);
            const slots = [...root.querySelectorAll('img')];

            // Livewire's wire:navigate re-runs this script: never keep two timers.
            clearInterval(window.cccCoverRotator);

            if (covers.length <= slots.length) {
                return;
            }

            const fade = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 500;
            let turn = 0;

            window.cccCoverRotator = setInterval(function () {
                if (document.hidden) {
                    return;
                }

                const shown = slots.map((img) => img.src);
                const candidates = covers.filter((url) => !shown.includes(url));
                const url = candidates[Math.floor(Math.random() * candidates.length)];
                const img = slots[turn];
                turn = (turn + 1) % slots.length;

                // Swap only once the next image is loaded, so there is never a blank frame.
                const preload = new Image();
                preload.onload = function () {
                    img.style.opacity = 0;
                    setTimeout(function () {
                        img.src = url;
                        img.style.opacity = 1;
                    }, fade);
                };
                preload.src = url;
            }, 5000);
        })();
    </script>
@endif
