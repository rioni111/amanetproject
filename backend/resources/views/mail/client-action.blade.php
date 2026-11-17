{{-- Client e-mail layout. Table-based, inline styles only (no browser-only CSS), 560px max, readable at 320px.
     Every value is escaped with {{ }} — client- and administrator-entered text can never become HTML. --}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{{ $c->subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#FAFAF8;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#FAFAF8;">{{ $c->preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FAFAF8;">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
        <tr>
          <td style="padding:0 4px 16px 4px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#111827;">
            <span style="display:inline-block;width:10px;height:10px;background-color:#0F766E;border-radius:2px;margin-right:8px;"></span>{{ $firmName }}
          </td>
        </tr>
        <tr>
          <td style="background-color:#FFFFFF;border:1px solid #E5E7EB;border-radius:10px;padding:28px 24px;font-family:Arial,Helvetica,sans-serif;color:#111827;">
            <h1 style="margin:0 0 16px 0;font-size:20px;line-height:28px;font-weight:bold;color:#111827;">{{ $c->headline }}</h1>
            <p style="margin:0 0 14px 0;font-size:15px;line-height:23px;">{{ $c->greeting }}</p>
            @foreach ($c->paragraphs as $paragraph)
              <p style="margin:0 0 14px 0;font-size:15px;line-height:23px;">{{ $paragraph }}</p>
            @endforeach

            @if (count($c->details) > 0)
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px 0;border-top:1px solid #E5E7EB;">
                @foreach ($c->details as $row)
                  <tr>
                    <td style="padding:9px 12px 9px 0;font-size:13px;line-height:19px;color:#6B7280;width:38%;vertical-align:top;border-bottom:1px solid #E5E7EB;">{{ $row['label'] }}</td>
                    <td style="padding:9px 0;font-size:14px;line-height:19px;color:#111827;vertical-align:top;border-bottom:1px solid #E5E7EB;">{{ $row['value'] }}</td>
                  </tr>
                @endforeach
              </table>
            @endif

            @if ($c->quote !== null)
              <p style="margin:0 0 4px 0;font-size:13px;line-height:19px;color:#6B7280;">{{ $c->quoteLabel }}</p>
              <div style="margin:0 0 18px 0;padding:12px 14px;background-color:#F3F4F6;border-left:3px solid #0F766E;font-size:14px;line-height:21px;color:#111827;white-space:pre-line;">{{ $c->quote }}</div>
            @endif

            @if ($c->actionUrl !== null)
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 18px 0;">
                <tr>
                  <td align="center" bgcolor="#0F766E" style="border-radius:8px;background-color:#0F766E;">
                    <a href="{{ $c->actionUrl }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:13px 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;line-height:20px;color:#FFFFFF;text-decoration:none;border-radius:8px;">{{ $c->actionLabel }}</a>
                  </td>
                </tr>
              </table>
              <p style="margin:0 0 14px 0;font-size:12px;line-height:18px;color:#6B7280;">If the button does not work, copy this complete address (including everything after the # sign) into your browser:<br><a href="{{ $c->actionUrl }}" style="color:#0F766E;word-break:break-all;">{{ $c->actionUrl }}</a></p>
            @endif

            @foreach ($c->notes as $note)
              <p style="margin:0 0 8px 0;font-size:13px;line-height:19px;color:#374151;">{{ $note }}</p>
            @endforeach
          </td>
        </tr>
        <tr>
          <td style="padding:16px 8px 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#6B7280;">
            {{ $firmName }}@if ($postalAddress) · {{ $postalAddress }}@endif<br>
            @if ($contactEmail)Questions? Reply to this e-mail or write to {{ $contactEmail }}.<br>@else Questions? Reply to this e-mail.<br>@endif
            Sent securely with Lexora Workspace. We will never ask for your password.
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
