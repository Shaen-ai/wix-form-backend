<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>New Form Submission</title>
    <!--[if mso]>
    <style>table,td{font-family:Arial,Helvetica,sans-serif!important}</style>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f0f0f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f0f5;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

                    {{-- Header --}}
                    <tr>
                        <td style="background:linear-gradient(135deg,#6c3ce0 0%,#9b6dff 50%,#c084fc 100%);padding:36px 40px 32px;">
                            <!--[if mso]>
                            <v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false" style="width:600px;height:130px;">
                            <v:fill type="gradient" color="#6c3ce0" color2="#c084fc" angle="135"/>
                            <v:textbox inset="36px,36px,36px,32px">
                            <![endif]-->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="color:#ffffff;font-size:16px;font-weight:600;letter-spacing:0.5px;padding-bottom:4px;">
                                        {{ $form->name }}
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color:#ffffff;font-size:26px;font-weight:700;padding-bottom:6px;">
                                        New Form Submission &#x1F514;
                                    </td>
                                </tr>
                                <tr>
                                    <td style="color:rgba(255,255,255,0.8);font-size:14px;">
                                        You have a new message from your website
                                    </td>
                                </tr>
                            </table>
                            <!--[if mso]></v:textbox></v:rect><![endif]-->
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px 40px 40px;">

                            {{-- Timestamp badge --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                                <tr>
                                    <td style="background-color:#fff3f3;border:1px solid #fecaca;border-radius:20px;padding:6px 16px;color:#dc2626;font-size:13px;font-weight:600;">
                                        &#9888; {{ $submission->submitted_at->format('M d, Y \a\t h:i A') }}
                                    </td>
                                </tr>
                            </table>

                            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 24px;">
                                A new contact form has been submitted. Here are the details:
                            </p>

                            {{-- Data table --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">
                                <tr>
                                    <td style="background-color:#f9fafb;padding:12px 20px;font-size:11px;font-weight:700;letter-spacing:1px;color:#6b7280;text-transform:uppercase;width:30%;border-bottom:1px solid #e5e7eb;">
                                        Field
                                    </td>
                                    <td style="background-color:#f9fafb;padding:12px 20px;font-size:11px;font-weight:700;letter-spacing:1px;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb;">
                                        Value
                                    </td>
                                </tr>
                                @foreach($rows as $index => $row)
                                <tr>
                                    @php $bg = $index % 2 === 0 ? '#ffffff' : '#f9fafb'; @endphp
                                    <td style="background-color:{{ $bg }};padding:14px 20px;font-size:14px;font-weight:600;color:#4338ca;vertical-align:top;border-bottom:1px solid #f3f4f6;">
                                        {{ $row['label'] }}
                                    </td>
                                    <td style="background-color:{{ $bg }};padding:14px 20px;font-size:14px;color:#374151;line-height:1.5;border-bottom:1px solid #f3f4f6;">
                                        @if($row['type'] === 'email')
                                            <a href="mailto:{{ $row['value'] }}" style="color:#4f46e5;text-decoration:none;">{{ $row['value'] }}</a>
                                        @elseif($row['type'] === 'url')
                                            <a href="{{ $row['value'] }}" style="color:#4f46e5;text-decoration:none;" target="_blank">{{ $row['value'] }}</a>
                                        @elseif(in_array($row['type'], ['subject', 'select', 'radio']))
                                            <strong>{{ $row['value'] }}</strong>
                                        @else
                                            {{ $row['value'] }}
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </table>

                            {{-- Reply button --}}
                            @if($submitterEmail)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:32px;">
                                <tr>
                                    <td align="center">
                                        <a href="mailto:{{ $submitterEmail }}?subject=Re: {{ urlencode($form->name) }}"
                                           style="display:inline-block;background-color:#4f46e5;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;padding:14px 32px;border-radius:8px;letter-spacing:0.3px;">
                                            Reply to {{ $submitterName ?? $submitterEmail }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            @endif

                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:0 40px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-top:1px solid #e5e7eb;padding-top:20px;text-align:center;color:#9ca3af;font-size:12px;line-height:1.5;">
                                        This email was sent because a form was submitted on your website.<br>
                                        {{ $submission->submitted_at->format('F j, Y \a\t g:i A T') }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
