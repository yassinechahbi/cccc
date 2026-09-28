@props(['member', 'days' => 60])

{{-- Needs $member->upcomingAbsences loaded. --}}
@if ($absence = $member->absenceWithin($days))
    <flux:badge size="sm" color="orange" icon="calendar-days" :title="$absence->note">
        @if ($absence->isCurrent())
            {{ __('Away until :date', ['date' => $absence->ends_on->format('Y-m-d')]) }}
        @else
            {{ __('Away :from – :to', ['from' => $absence->starts_on->format('Y-m-d'), 'to' => $absence->ends_on->format('Y-m-d')]) }}
        @endif
    </flux:badge>
@endif
