<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ sns_name() }}</title>
</head>
{{-- The body is plain text rendered by the mail-template engine; it is HTML-escaped here and never run
     through Markdown, so a member-supplied value cannot inject a link, image, or script. --}}
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,'Segoe UI',Roboto,'Hiragino Kaku Gothic ProN',Meiryo,sans-serif; color:#1f2933;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background-color:#ffffff; border:1px solid #e4e4e7; border-radius:8px;">
<tr><td style="padding:20px 28px; border-bottom:1px solid #e4e4e7;">
<a href="{{ url('/') }}" style="font-size:18px; font-weight:600; color:#1f2933; text-decoration:none;">{{ sns_name() }}</a>
</td></tr>
<tr><td style="padding:28px; font-size:14px; line-height:1.7;">{!! nl2br(e($body)) !!}</td></tr>
<tr><td style="padding:18px 28px; border-top:1px solid #e4e4e7; color:#9ca3af; font-size:12px;">
&copy; {{ date('Y') }} {{ sns_name() }}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
