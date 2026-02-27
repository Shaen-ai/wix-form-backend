<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Thank you</title>
</head>
<body>
    @if($settings->auto_reply_body)
        {!! nl2br(e($settings->auto_reply_body)) !!}
    @else
        <p>Thank you for your submission. We have received your message.</p>
    @endif
</body>
</html>
