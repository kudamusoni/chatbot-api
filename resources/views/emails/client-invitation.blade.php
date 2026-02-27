<!doctype html>
<html lang="en">
<body>
    <p>You have been invited to join <strong>{{ $clientName }}</strong> as <strong>{{ $role }}</strong>.</p>
    <p>This invitation expires at {{ $expiresAtIso }}.</p>
    <p><a href="{{ $acceptUrl }}">Accept invitation</a></p>
</body>
</html>

