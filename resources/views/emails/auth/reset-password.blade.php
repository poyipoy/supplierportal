<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>Reset your password</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; color:#172033; font-family:Arial, Helvetica, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f4f6f8; margin:0; padding:28px 12px; width:100%;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#ffffff; border:1px solid #dbe4ef; border-radius:16px; max-width:600px; overflow:hidden; width:100%;">
                    <tr>
                        <td style="background:linear-gradient(135deg, #1f5fa6 0%, #174a85 100%); padding:28px 36px;">
                            <p style="color:#ffffff; font-size:20px; font-weight:700; letter-spacing:0.2px; line-height:1.3; margin:0;">ADASI Supplier Portal</p>
                            <p style="color:#dcecff; font-size:13px; line-height:1.5; margin:6px 0 0;">PT. Astra Daido Steel Indonesia</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:34px 36px 28px;">
                            <h1 style="color:#172033; font-size:24px; font-weight:700; line-height:1.3; margin:0 0 18px;">Reset your password</h1>
                            <p style="color:#44546a; font-size:15px; line-height:1.65; margin:0 0 16px;">
                                Hello{{ $recipientName !== '' ? ', '.$recipientName : '' }},
                            </p>
                            <p style="color:#44546a; font-size:15px; line-height:1.65; margin:0 0 24px;">
                                We received a request to reset the password for your ADASI Supplier Portal account. Use the button below to choose a new password.
                            </p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;">
                                <tr>
                                    <td align="center" bgcolor="#1f5fa6" style="border-radius:8px;">
                                        <a href="{{ $resetUrl }}" style="background-color:#1f5fa6; border:1px solid #1f5fa6; border-radius:8px; color:#ffffff; display:inline-block; font-size:15px; font-weight:700; line-height:1; padding:14px 22px; text-decoration:none;">Reset Password</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="color:#44546a; font-size:14px; line-height:1.65; margin:0 0 16px;">
                                This link expires in {{ $expiresInMinutes }} minutes. If you did not request a password reset, no further action is required.
                            </p>
                            <p style="border-top:1px solid #e6ecf3; color:#667085; font-size:12px; line-height:1.6; margin:24px 0 0; padding-top:20px; word-break:break-word;">
                                If the button does not work, copy and paste this address into your browser:<br>
                                <a href="{{ $resetUrl }}" style="color:#1f5fa6; text-decoration:underline;">{{ $resetUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#f8fafc; border-top:3px solid #c0392b; color:#667085; font-size:12px; line-height:1.5; padding:18px 36px;">
                            This is an automated security message from ADASI Supplier Portal. Please do not reply to this email.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
