<!doctype html>
<html lang="en">
<body>
    <p>Hello {{ $recipientName }},</p>
    <p>Please verify your email to continue using your dashboard account.</p>
    <p><a href="{{ $verificationUrl }}">Verify email</a></p>
    <p>If you did not request this, you can ignore this email.</p>
</body>
</html>

