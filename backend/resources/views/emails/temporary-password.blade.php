<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your temporary GATRH password</title>
</head>
<body style="margin:0;padding:0;background:#f2f5fa;color:#172b4d;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Your GATRH account is ready. Use the temporary password to sign in, then change it.</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f2f5fa;">
        <tr>
            <td align="center" style="padding:36px 14px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr><td style="height:7px;background:#172b4d;font-size:0;line-height:0;">&nbsp;</td></tr>
                    <tr>
                        <td style="padding:25px 32px 22px;border-bottom:1px solid #e8edf4;">
                            <p style="margin:0;color:#172b4d;font-size:16px;font-weight:700;letter-spacing:.3px;">GATRH</p>
                            <p style="margin:4px 0 0;color:#7b8799;font-size:11px;">Gathering Attendance Tracking and Reporting Hub</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:34px 32px 30px;">
                            <p style="margin:0 0 12px;color:#3976c5;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;">Account access</p>
                            <h1 style="margin:0 0 12px;color:#172b4d;font-size:25px;line-height:1.25;">Welcome to GATRH, {{ $name }}</h1>
                            <p style="margin:0 0 24px;color:#5c6880;font-size:15px;line-height:1.65;">An administrator created a GATRH account for you. Sign in using your email address and this temporary password:</p>
                            <p style="margin:0 0 22px;"><a href="{{ $appUrl }}" style="display:inline-block;padding:13px 22px;border-radius:7px;background:#172b4d;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Open GATRH and sign in</a></p>
                            <p style="margin:0 0 20px;color:#5c6880;font-size:12px;line-height:1.6;">Or open: <a href="{{ $appUrl }}" style="color:#3976c5;">{{ $appUrl }}</a></p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 24px;background:#f1f5fb;border:1px solid #dce5f1;border-radius:9px;">
                                <tr><td align="center" style="padding:20px 12px;color:#172b4d;font-size:22px;font-weight:700;letter-spacing:1px;word-break:break-all;">{{ $temporaryPassword }}</td></tr>
                            </table>
                            <div style="padding:14px 16px;border-left:3px solid #e3ad42;background:#fffaf0;">
                                <p style="margin:0;color:#6b5b39;font-size:13px;line-height:1.6;"><strong>For your security, change this password as soon as you sign in.</strong> Choose a password you do not use elsewhere, and never share it with anyone.</p>
                            </div>
                            <p style="margin:27px 0 0;color:#5c6880;font-size:14px;line-height:1.6;">If you were not expecting this account, contact your administrator.<br><br>Thank you,<br><strong style="color:#172b4d;">The GATRH Team</strong></p>
                        </td>
                    </tr>
                    <tr><td style="padding:17px 32px;background:#f8fafd;border-top:1px solid #e8edf4;color:#8a94a4;font-size:11px;line-height:1.6;">This is an automated account email. Please do not reply.</td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
