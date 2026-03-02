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
<body style="margin:0;padding:0;background-color:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f5;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">

                    {{-- Header --}}
                    <tr>
                        <td style="padding:28px 36px;border-bottom:1px solid #e5e7eb;">
                            <p style="margin:0;font-size:13px;font-weight:500;color:#6b7280;">
                                {{ $form->name }}
                            </p>
                            <h1 style="margin:6px 0 0;font-size:20px;font-weight:600;color:#111827;">
                                New form submission
                            </h1>
                            <p style="margin:8px 0 0;font-size:14px;color:#6b7280;">
                                {{ $submission->submitted_at->format('M d, Y \a\t g:i A') }}
                            </p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:28px 36px 36px;">

                            <p style="color:#374151;font-size:15px;line-height:1.6;margin:0 0 20px;">
                                A new form has been submitted. Details below:
                            </p>

                            {{-- Data table --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">
                                <tr>
                                    <td style="background-color:#f9fafb;padding:12px 16px;font-size:11px;font-weight:600;letter-spacing:0.5px;color:#6b7280;text-transform:uppercase;width:30%;border-bottom:1px solid #e5e7eb;">
                                        Field
                                    </td>
                                    <td style="background-color:#f9fafb;padding:12px 16px;font-size:11px;font-weight:600;letter-spacing:0.5px;color:#6b7280;text-transform:uppercase;border-bottom:1px solid #e5e7eb;">
                                        Value
                                    </td>
                                </tr>
                                @foreach($rows as $index => $row)
                                <tr>
                                    @php $bg = $index % 2 === 0 ? '#ffffff' : '#fafafa'; @endphp
                                    <td style="background-color:{{ $bg }};padding:14px 16px;font-size:14px;font-weight:500;color:#374151;vertical-align:top;border-bottom:1px solid #f3f4f6;">
                                        {{ $row['label'] }}
                                    </td>
                                    <td style="background-color:{{ $bg }};padding:14px 16px;font-size:14px;color:#374151;line-height:1.5;border-bottom:1px solid #f3f4f6;">
                                        @if($row['type'] === 'file_upload')
                                            @foreach($row['fileEntries'] ?? [] as $entry)
                                                <div style="margin-bottom:4px;">
                                                    @if($entry['attached'])
                                                        <span style="color:#059669;">{{ $entry['name'] }}</span> <span style="color:#6b7280;font-size:12px;">(attached)</span>
                                                    @else
                                                        <a href="{{ $entry['downloadUrl'] }}" style="color:#2563eb;text-decoration:underline;" target="_blank">{{ $entry['name'] }}</a> <span style="color:#6b7280;font-size:12px;">(download link, expires in 7 days)</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        @elseif($row['type'] === 'email')
                                            <a href="mailto:{{ $row['value'] }}" style="color:#374151;text-decoration:underline;">{{ $row['value'] }}</a>
                                        @elseif($row['type'] === 'url')
                                            <a href="{{ $row['value'] }}" style="color:#374151;text-decoration:underline;" target="_blank">{{ $row['value'] }}</a>
                                        @elseif(in_array($row['type'], ['subject', 'select', 'radio']))
                                            {{ $row['value'] }}
                                        @else
                                            {{ $row['value'] }}
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </table>

                            {{-- Reply link --}}
                            @if($submitterEmail)
                            <p style="margin:24px 0 0;font-size:14px;color:#6b7280;">
                                <a href="mailto:{{ $submitterEmail }}?subject=Re: {{ urlencode($form->name) }}"
                                   style="color:#374151;font-weight:500;text-decoration:underline;">
                                    Reply to {{ $submitterName ?? $submitterEmail }}
                                </a>
                            </p>
                            @endif

                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 36px 28px;border-top:1px solid #e5e7eb;">
                            <p style="margin:0;font-size:12px;color:#9ca3af;">
                                Sent from {{ $form->name }} · {{ $submission->submitted_at->format('F j, Y \a\t g:i A T') }}
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
