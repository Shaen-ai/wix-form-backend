<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Thank you</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f5;">
        <tr>
            <td align="center" style="padding:40px 20px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">

                    {{-- Header --}}
                    <tr>
                        <td style="padding:40px 40px 24px;border-bottom:1px solid #eee;">
                            <p style="margin:0;font-size:13px;font-weight:500;color:#6b7280;letter-spacing:0.5px;">
                                {{ $form->name }}
                            </p>
                            <h1 style="margin:8px 0 0;font-size:22px;font-weight:600;color:#111827;letter-spacing:-0.02em;">
                                Thank you
                            </h1>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px 40px 40px;">
                            <div style="color:#374151;font-size:16px;line-height:1.7;">
                                @if($settings->auto_reply_body)
                                    {!! nl2br(e($settings->auto_reply_body)) !!}
                                @else
                                    <p style="margin:0;">Thank you for your submission. We have received your message and will get back to you soon.</p>
                                @endif
                            </div>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:24px 40px 32px;border-top:1px solid #eee;">
                            <p style="margin:0;font-size:12px;color:#9ca3af;">
                                This is an automated confirmation from {{ $form->name }}.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
