<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Website enquiry</title>
</head>
<body style="margin:0;padding:0;background:#f3f0e8;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f0e8;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fbfaf6;border:1px solid #d7d3c8;">
                    <tr>
                        <td style="background:#242723;padding:22px 28px;">
                            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:700;color:#ffffff;">IUBits <span style="color:#f5c842;">POS</span></p>
                            <p style="margin:6px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#f5c842;">Website enquiry</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#20221f;">
                            <p style="margin:0 0 16px;color:#3c423c;">Someone sent a message from the contact page.</p>
                            <p style="margin:0 0 6px;"><strong>Name</strong><br>{{ $name }}</p>
                            <p style="margin:0 0 6px;"><strong>Email</strong><br><a href="mailto:{{ $email }}" style="color:#167c73;">{{ $email }}</a></p>
                            @if ($phone)
                                <p style="margin:0 0 6px;"><strong>Phone</strong><br>{{ $phone }}</p>
                            @endif
                            <p style="margin:18px 0 0;"><strong>Message</strong></p>
                            <p style="margin:6px 0 0;white-space:pre-wrap;">{{ $enquiry }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
