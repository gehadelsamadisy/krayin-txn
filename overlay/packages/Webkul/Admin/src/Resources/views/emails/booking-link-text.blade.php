{{-- Booking-link emails, plain-text part (same wording as booking-link.blade.php). --}}
@php
    $kind = $kind ?? 'link';
    $offer = $kind !== 'final' && $url && $times;
@endphp
Hi {!! $firstName !!},

{!! trim((string) $intro) !!}
@if ($offer)

@foreach ($times as $time)
- {!! $time !!}
@endforeach

Choose your time here:
{!! $url !!}
@endif
@if (trim((string) $closing) !== '')

{!! trim((string) $closing) !!}
@endif

{!! $sender['rep_name'] !!}
{!! $sender['company'] !!}
@if ($sender['rep_cell'])
Cell: {!! $sender['rep_cell'] !!}
@endif
@if ($sender['office_phone'])
Office: {!! $sender['office_phone'] !!}
@endif
