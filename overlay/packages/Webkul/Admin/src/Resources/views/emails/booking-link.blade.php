{{-- Booking-link emails (HTML part), Support\Contact\BookingEmail. Table
     layout and inline styles so it renders the same in Outlook, Gmail and
     phone mail apps. Every word comes from \Webkul\Admin\Support\Contact\BookingEmail::data(): $subject,
     $intro, $closing (per email; may have been edited on the Send booking
     link form) and $parts (header, greeting, button, fallback, signature,
     shared by all emails), all from Settings > TXN Settings > Emails. An empty
     part is left out. The ids let the previews update while typing. --}}
@php
    $accent = $sender['accent'];
    $kind = $kind ?? 'link';
    $offer = $kind !== 'final' && $url && $times;
    $parts = $parts ?? [];
    $hide = fn (?string $text) => trim((string) $text) === '' ? ' display:none;' : '';

    // Signature: the phone numbers become tap-to-call links.
    $signature = \Webkul\Admin\Support\Contact\BookingEmail::html($parts['signature'] ?? '');
    foreach (array_filter([$sender['rep_cell'], $sender['office_phone']]) as $phone) {
        $signature = str_replace(e($phone), '<a href="tel:'.preg_replace('/[^0-9+]/', '', $phone).'" style="color:#52606D; text-decoration:none;">'.e($phone).'</a>', $signature);
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ \Webkul\Admin\Support\Contact\BookingEmail::plain($subject ?? '') ?: $sender['company'] }}</title>
</head>
<body style="margin:0; padding:0; background:#F3F5F7; -webkit-text-size-adjust:100%;">
    {{-- Inbox preview line --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ \Illuminate\Support\Str::limit(\Webkul\Admin\Support\Contact\BookingEmail::plain($intro), 110) }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F5F7;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background:#FFFFFF; border:1px solid #E3E7EB; border-radius:10px;">
                    {{-- Header --}}
                    <tr>
                        <td id="txn-header" style="padding:20px 28px; border-bottom:3px solid {{ $accent }}; font-family:Segoe UI, Arial, sans-serif; font-size:18px; font-weight:700; color:#1F2933;{{ $hide($parts['header'] ?? '') }}">{!! \Webkul\Admin\Support\Contact\BookingEmail::html($parts['header'] ?? '') !!}</td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:28px; font-family:Segoe UI, Arial, sans-serif; font-size:15px; line-height:1.6; color:#323F4B;">
                            <p id="txn-greeting" style="margin:0 0 16px;{{ $hide($parts['greeting'] ?? '') }}">{!! \Webkul\Admin\Support\Contact\BookingEmail::html($parts['greeting'] ?? '') !!}</p>

                            <p id="txn-intro" style="margin:0 0 16px;">{!! \Webkul\Admin\Support\Contact\BookingEmail::html($intro) !!}</p>

                            @if ($offer)
                                <table id="txn-times" role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                                    @foreach ($times as $time)
                                        <tr>
                                            <td style="padding:4px 10px 4px 0; vertical-align:top; color:{{ $accent }}; font-size:15px;">&#9679;</td>
                                            <td style="padding:4px 0; font-family:Segoe UI, Arial, sans-serif; font-size:15px; color:#1F2933;"><strong>{{ $time }}</strong></td>
                                        </tr>
                                    @endforeach
                                </table>

                                {{-- Button --}}
                                <table id="txn-button-box" role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;{{ $hide($parts['button'] ?? '') }}">
                                    <tr>
                                        <td align="center" bgcolor="{{ $accent }}" style="border-radius:6px;">
                                            <a id="txn-button" href="{{ $url }}" target="_blank" style="display:inline-block; padding:13px 28px; font-family:Segoe UI, Arial, sans-serif; font-size:16px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:6px;">{!! \Webkul\Admin\Support\Contact\BookingEmail::html($parts['button'] ?? '') !!}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <p id="txn-closing" style="margin:0 0 16px; font-size:14px; color:#52606D;{{ $hide($closing) }}">{!! \Webkul\Admin\Support\Contact\BookingEmail::html($closing) !!}</p>

                            @if ($offer)
                                <p style="margin:0; font-size:14px; color:#52606D;">
                                    <span id="txn-fallback">{!! \Webkul\Admin\Support\Contact\BookingEmail::html($parts['fallback'] ?? '') !!}</span><br>
                                    <a href="{{ $url }}" style="color:{{ $accent }}; word-break:break-all;">{{ $url }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Signature --}}
                    <tr>
                        <td id="txn-signature" style="padding:20px 28px 26px; border-top:1px solid #E3E7EB; font-family:Segoe UI, Arial, sans-serif; font-size:14px; line-height:1.55; color:#52606D;{{ $hide($parts['signature'] ?? '') }}">{!! $signature !!}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
