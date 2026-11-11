{{-- Booking-link emails (HTML part), Support\Contact\BookingEmail. Table
     layout and inline styles so it renders the same in Outlook, Gmail and
     phone mail apps. The wording ($intro, $closing) comes from BookingEmail
     and may have been edited on the Send booking link form; the times,
     button and signature are fixed. Data: $kind, $firstName, $intro,
     $closing, $times, $url, $sender. The ids let the form's preview update. --}}
@php
    $accent = $sender['accent'];
    $kind = $kind ?? 'link';
    $offer = $kind !== 'final' && $url && $times;
    // Plain text with line breaks; "free, no-obligation estimate" in bold.
    $format = fn (?string $text) => str_replace(
        'free, no-obligation estimate',
        '<strong>free, no-obligation estimate</strong>',
        nl2br(e(trim((string) $text)))
    );
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $subject ?? 'TXN Home Services' }}</title>
</head>
<body style="margin:0; padding:0; background:#F3F5F7; -webkit-text-size-adjust:100%;">
    {{-- Inbox preview line --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ \Illuminate\Support\Str::limit(trim((string) $intro), 110) }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F5F7;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; background:#FFFFFF; border:1px solid #E3E7EB; border-radius:10px;">
                    {{-- Header --}}
                    <tr>
                        <td style="padding:20px 28px; border-bottom:3px solid {{ $accent }}; font-family:Segoe UI, Arial, sans-serif; font-size:18px; font-weight:700; color:#1F2933;">
                            {{ $sender['company'] }}
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:28px; font-family:Segoe UI, Arial, sans-serif; font-size:15px; line-height:1.6; color:#323F4B;">
                            <p style="margin:0 0 16px;">Hi {{ $firstName }},</p>

                            <p id="txn-intro" style="margin:0 0 16px;">{!! $format($intro) !!}</p>

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
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                                    <tr>
                                        <td align="center" bgcolor="{{ $accent }}" style="border-radius:6px;">
                                            <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:13px 28px; font-family:Segoe UI, Arial, sans-serif; font-size:16px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:6px;">
                                                Choose your time
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <p id="txn-closing" style="margin:0 0 16px; font-size:14px; color:#52606D;{{ trim((string) $closing) === '' ? ' display:none;' : '' }}">{!! $format($closing) !!}</p>

                            @if ($offer)
                                <p style="margin:0; font-size:14px; color:#52606D;">
                                    If the button doesn't work, copy this link into your browser:<br>
                                    <a href="{{ $url }}" style="color:{{ $accent }}; word-break:break-all;">{{ $url }}</a>
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Signature --}}
                    <tr>
                        <td style="padding:20px 28px 26px; border-top:1px solid #E3E7EB; font-family:Segoe UI, Arial, sans-serif; font-size:14px; line-height:1.55; color:#52606D;">
                            <strong style="color:#1F2933;">{{ $sender['rep_name'] }}</strong><br>
                            {{ $sender['company'] }}<br>
                            @if ($sender['rep_cell'])Cell: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $sender['rep_cell']) }}" style="color:#52606D; text-decoration:none;">{{ $sender['rep_cell'] }}</a><br>@endif
                            @if ($sender['office_phone'])Office: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $sender['office_phone']) }}" style="color:#52606D; text-decoration:none;">{{ $sender['office_phone'] }}</a>@endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
