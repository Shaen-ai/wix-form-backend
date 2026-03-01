<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Feature Request</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f0f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f0f5;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#6c3ce0 0%,#9b6dff 50%,#c084fc 100%);padding:36px 40px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="color:#ffffff;font-size:26px;font-weight:700;padding-bottom:6px;">
                                        Feature Request &#x1F4AC;
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color:rgba(255,255,255,0.8);font-size:14px;">
                                        A user requested a feature that isn't available yet
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 40px 40px;">
                            <p style="margin:0 0 16px;font-size:14px;color:#374151;">
                                <strong>From:</strong> <a href="mailto:{{ $fromEmail }}" style="color:#4f46e5;text-decoration:none;">{{ $fromEmail }}</a>
                            </p>
                            <p style="margin:0 0 16px;font-size:14px;color:#374151;">
                                <strong>Subject:</strong> {{ $subject }}
                            </p>
                            <div style="margin:16px 0 0;padding:16px;background:#f9fafb;border-radius:8px;border-left:4px solid #6c3ce0;">
                                <p style="margin:0 0 8px;font-size:12px;color:#6b7280;font-weight:600;">Description</p>
                                <p style="margin:0;font-size:14px;color:#374151;white-space:pre-wrap;">{{ $description }}</p>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
