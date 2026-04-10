<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>MQ Monitoring — Password Reset Code</title>
</head>
<body style="margin:0;padding:0;background-color:#0d1117;font-family:'Segoe UI',Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

  <!-- Outer wrapper -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0d1117;padding:48px 16px;">
    <tr>
      <td align="center" valign="top">

        <!-- Card container -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;background-color:#161b27;border-radius:12px;overflow:hidden;">

          <!-- ── Accent top bar ── -->
          <tr>
            <td style="background-color:#dc2626;height:4px;font-size:0;line-height:0;">&nbsp;</td>
          </tr>

          <!-- ── Header ── -->
          <tr>
            <td align="center" style="background-color:#0a0e1a;padding:32px 40px 28px;">
              <!-- Brand badge -->
              <table cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td align="center" style="background-color:#dc2626;border-radius:6px;padding:5px 14px;">
                    <span style="color:#ffffff;font-size:10px;font-weight:700;letter-spacing:3px;text-transform:uppercase;font-family:'Segoe UI',Arial,sans-serif;">MQ MONITORING</span>
                  </td>
                </tr>
              </table>
              <!-- Title -->
              <p style="color:#f1f5f9;font-size:21px;font-weight:700;margin:18px 0 0;letter-spacing:0.3px;line-height:1.3;">Password Reset Request</p>
              <p style="color:#9ca3af;font-size:13px;margin:6px 0 0;">Intelligent Real-Time Workplace Monitoring</p>
            </td>
          </tr>

          <!-- ── Divider ── -->
          <tr>
            <td style="background-color:#1a1f2e;height:1px;font-size:0;line-height:0;">&nbsp;</td>
          </tr>

          <!-- ── Body ── -->
          <tr>
            <td style="padding:36px 40px 32px;">

              <!-- Greeting -->
              <p style="color:#d1d5db;font-size:15px;line-height:1.65;margin:0 0 16px;">
                Hello <strong style="color:#ffffff;">{{ $name }}</strong>,
              </p>

              <!-- Message -->
              <p style="color:#9ca3af;font-size:14px;line-height:1.7;margin:0 0 32px;">
                We received a request to reset the password for your
                <strong style="color:#d1d5db;">MQ Monitoring</strong> account.
                Use the verification code below to continue.
              </p>

              <!-- ── OTP box ── -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                <tr>
                  <td align="center">
                    <table cellpadding="0" cellspacing="0" border="0" style="background-color:#0f1419;border:2px solid #dc2626;border-radius:12px;">
                      <tr>
                        <td align="center" style="padding:28px 52px;">
                          <p style="color:#9ca3af;font-size:10px;font-weight:700;letter-spacing:3px;text-transform:uppercase;margin:0 0 14px;font-family:'Segoe UI',Arial,sans-serif;">YOUR RESET CODE</p>
                          <p style="color:#dc2626;font-size:44px;font-weight:700;letter-spacing:12px;margin:0;font-family:'Courier New',Courier,monospace;line-height:1;">{{ $otp }}</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              <!-- ── Expiry warning ── -->
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
                <tr>
                  <td style="background-color:#3730a399;border-left:3px solid #dc2626;border-radius:0 6px 6px 0;padding:12px 16px;">
                    <p style="color:#fca5a5;font-size:13px;font-weight:600;margin:0;line-height:1.5;">
                       This code expires in <strong>10 minutes</strong>.
                      Do not share it with anyone.
                    </p>
                  </td>
                </tr>
              </table>

              <!-- ── Security note ── -->
              <p style="color:#6b7280;font-size:12px;line-height:1.65;margin:0;border-top:1px solid #1a1f2e;padding-top:20px;">
                If you did not request a password reset, you can safely ignore this email.
                Your password will remain unchanged and your account is secure.
              </p>

            </td>
          </tr>

          <!-- ── Footer ── -->
          <tr>
            <td style="background-color:#050912;padding:20px 40px;text-align:center;border-top:1px solid #1a1f2e;">
              <p style="color:#4b5563;font-size:11px;margin:0 0 6px;line-height:1.5;">
                <strong style="color:#9ca3af;">MQ Monitoring</strong> &mdash; Intelligent Real-Time Workplace Monitoring System
              </p>
              <p style="color:#374151;font-size:11px;margin:0;line-height:1.5;">
                This is an automated security notification. Please do not reply to this email.
              </p>
            </td>
          </tr>

          <!-- ── Accent bottom bar ── -->
          <tr>
            <td style="background-color:#dc2626;height:2px;font-size:0;line-height:0;">&nbsp;</td>
          </tr>

        </table>
        <!-- /Card container -->

      </td>
    </tr>
  </table>

</body>
</html>
