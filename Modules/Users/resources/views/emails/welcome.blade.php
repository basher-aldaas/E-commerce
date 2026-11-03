<!DOCTYPE html>
<html lang="en">
<body style="font-family: Arial, Helvetica, sans-serif; color: #111827; line-height: 1.6;">
    <h2 style="margin-bottom: 8px;">Welcome, {{ $user->name }}! 🎉</h2>

    <p>Your account has been created successfully with the email
        <strong>{{ $user->email }}</strong>.</p>

    <p>You can now sign in and start shopping on {{ config('app.name') }}.</p>

    <p style="margin-top: 24px; color: #6b7280; font-size: 13px;">
        If you did not create this account, please ignore this email.
    </p>
</body>
</html>