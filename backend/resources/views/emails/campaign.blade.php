<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $companyInfo['name'] ?? config('app.name') }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f5f7; font-family: Arial, Helvetica, sans-serif; color:#1f2933;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px; width:100%; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:#111827; padding:20px 28px; color:#ffffff; font-size:18px; font-weight:bold;">
                            {{ $companyInfo['name'] ?? config('app.name') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px; font-size:15px; line-height:1.6;">
                            @if ($recipientName)
                                <p style="margin:0 0 16px;">Dear {{ $recipientName }},</p>
                            @endif
                            <div>{!! $htmlBody !!}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px; background:#f9fafb; border-top:1px solid #e5e7eb; font-size:12px; color:#6b7280;">
                            @if (! empty($companyInfo['address']))
                                <div>{{ $companyInfo['address'] }}</div>
                            @endif
                            @if (! empty($companyInfo['phone']) || ! empty($companyInfo['email']))
                                <div>
                                    {{ $companyInfo['phone'] ?? '' }}
                                    @if (! empty($companyInfo['phone']) && ! empty($companyInfo['email'])) &middot; @endif
                                    {{ $companyInfo['email'] ?? '' }}
                                </div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
