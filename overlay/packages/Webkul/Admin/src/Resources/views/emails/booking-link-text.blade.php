{{-- Booking-link emails, plain-text part (same wording as booking-link.blade.php,
     from Settings > TXN Settings > Emails; **bold** marks removed; empty parts left out). --}}
@php
    $kind = $kind ?? 'link';
    $offer = $kind !== 'final' && $url && $times;
    $parts = $parts ?? [];
    $greeting = \Webkul\Admin\Support\Contact\BookingEmail::plain($parts['greeting'] ?? '');
    $timesHeading = \Webkul\Admin\Support\Contact\BookingEmail::plain($parts['times_heading'] ?? '');
    $signature = \Webkul\Admin\Support\Contact\BookingEmail::plain($parts['signature'] ?? '');
@endphp
@if ($greeting !== '')
{!! $greeting !!}

@endif
{!! \Webkul\Admin\Support\Contact\BookingEmail::plain($intro) !!}
@if ($offer)

@foreach ($times as $time)
- {!! $time !!}
@endforeach

@if ($timesHeading !== '')
{!! $timesHeading !!}
@endif
{!! $url !!}
@endif
@if (trim((string) $closing) !== '')

{!! \Webkul\Admin\Support\Contact\BookingEmail::plain($closing) !!}
@endif
@if ($signature !== '')

{!! $signature !!}
@endif
