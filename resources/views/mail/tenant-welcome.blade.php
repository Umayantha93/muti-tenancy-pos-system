<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f0e8;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f0e8;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fbfaf6;border:1px solid #d7d3c8;">
                    <tr>
                        <td style="background:#242723;padding:22px 28px;">
                            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:22px;font-weight:700;letter-spacing:0.04em;color:#ffffff;">
                                IUBits <span style="color:#f5c842;">POS</span>
                            </p>
                            <p style="margin:6px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#f5c842;">
                                Your account is ready
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 12px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#20221f;">
                                Hi {{ $ownerName }},
                            </p>
                            <p style="margin:0 0 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#3c423c;">
                                Your business account for <strong style="color:#20221f;">{{ $businessName }}</strong> has been created. Use the details below to sign in, then change the temporary password.
                            </p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f0e8;border:1px solid #d7d3c8;">
                                <tr>
                                    <td style="padding:8px 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#6f746e;">Email</td>
                                </tr>
                                <tr>
                                    <td style="padding:2px 16px 12px;font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#20221f;">{{ $loginEmail }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 16px;border-top:1px solid #d7d3c8;"></td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 16px 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#6f746e;">Temporary password</td>
                                </tr>
                                <tr>
                                    <td style="padding:2px 16px 14px;font-family:Consolas,Menlo,monospace;font-size:16px;color:#20221f;">{{ $plainPassword }}</td>
                                </tr>
                            </table>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 8px;">
                                <tr>
                                    <td style="background:#167c73;">
                                        <a href="{{ $loginUrl }}" style="display:inline-block;padding:12px 22px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">
                                            Sign in
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:14px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#6f746e;">
                                Or open this link:<br>
                                <a href="{{ $loginUrl }}" style="color:#167c73;word-break:break-all;">{{ $loginUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 22px;border-top:1px solid #d7d3c8;">
                            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#6f746e;">
                                This password is temporary. Change it after your first sign-in.<br>
                                — IUBits
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
