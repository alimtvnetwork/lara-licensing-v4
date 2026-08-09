{{-- Plan 09 step: password reset plain-HTML email body. --}}
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Reset your password</title></head>
<body style="font-family: Arial, sans-serif; color: #0f172a; background: #ffffff; padding: 24px;">
  <h1 style="font-size: 20px; margin: 0 0 12px;">Reset your Licensing Portal password</h1>
  <p style="font-size: 14px; line-height: 1.5;">
    We received a request to reset the password on your Licensing Portal account.
    Click the button below to choose a new password.
  </p>
  <p style="margin: 24px 0;">
    <a href="{{ $resetUrl }}"
       style="display: inline-block; padding: 10px 16px; background: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600;">
      Reset password
    </a>
  </p>
  <p style="font-size: 12px; color: #64748b;">
    This link expires at <strong>{{ $expiresAtIso }}</strong> and can be used only once.
    If you did not request a reset, you can safely ignore this email.
  </p>
  <p style="font-size: 12px; color: #64748b; word-break: break-all;">
    Or paste this URL into your browser: {{ $resetUrl }}
  </p>
</body>
</html>
