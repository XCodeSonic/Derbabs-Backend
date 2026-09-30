<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Verify Your Identity</title>
  <style>
    /* ----- RESET & FALLBACKS ----- */
    body, table, td, p, a, div, span {
      margin: 0;
      padding: 0;
      border: 0;
      font-size: 100%;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      line-height: 1.5;
      mso-line-height-alt: 150%;
    }
    .ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font, .ExternalClass td, .ExternalClass div {
      line-height: 100%;
    }
    /* ----- RESPONSIVE ----- */
    @media only screen and (max-width: 600px) {
      table[class="container"] {
        width: 100% !important;
        padding: 20px 16px !important;
      }
      td[class="card"] {
        padding: 28px 20px 24px !important;
        border-radius: 16px !important;
      }
      div[class="otp-digits"] {
        font-size: 36px !important;
        letter-spacing: 12px !important;
        padding: 14px 20px !important;
      }
      div[class="secure-badge"] {
        font-size: 10px !important;
        padding: 4px 14px !important;
      }
      table[class="footer-links"] td {
        display: block !important;
        padding: 4px 0 !important;
      }
      table[class="footer-links"] td[class="divider"] {
        display: none !important;
      }
      h2[class="greeting"] {
        font-size: 20px !important;
      }
      .brand-text {
        font-size: 22px !important;
      }
    }
    /* ----- DARK MODE ----- */
    @media (prefers-color-scheme: dark) {
      body { background-color: #0f172a !important; }
      .dark-card { background: #1e293b !important; border-top-color: #3b82f6 !important; }
      .dark-text-primary { color: #f1f5f9 !important; }
      .dark-text-secondary { color: #cbd5e1 !important; }
      .dark-text-muted { color: #94a3b8 !important; }
      .dark-otp-bg { background: #0f172a !important; border-color: #334155 !important; }
      .dark-otp-code { color: #60a5fa !important; }
      .dark-security { background: #1e293b !important; border-left-color: #3b82f6 !important; }
      .dark-security-text { color: #e2e8f0 !important; }
      .dark-divider { background: linear-gradient(to right, transparent, #334155, transparent) !important; }
      .dark-footer-border { border-top-color: #334155 !important; }
      .dark-badge { background: #1e293b !important; color: #93c5fd !important; border-color: #334155 !important; }
      .dark-greeting { color: #f1f5f9 !important; }
      .dark-greeting-strong { color: #ffffff !important; }
      .dark-message { color: #cbd5e1 !important; }
      .dark-muted-link { color: #94a3b8 !important; }
      .dark-icon-bg { background: #1e293b !important; border-color: #334155 !important; }
      .dark-otp-box { background: #0f172a !important; border-color: #334155 !important; }
      .dark-expiry { background: #0f172a !important; border-color: #334155 !important; }
    }
    /* icon alignment helper */
    .icon-inline {
      display: inline-flex;
      align-items: center;
      vertical-align: middle;
      margin-right: 4px;
    }
    .icon-inline svg {
      display: block;
    }
    .footer-link-item {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      color: #94a3b8;
      font-size: 12px;
      text-decoration: none;
    }
    .footer-link-item svg {
      flex-shrink: 0;
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f4f7fc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">

  <!--[if (gte mso 9)|(IE)]>
  <table width="600" align="center" cellpadding="0" cellspacing="0" border="0">
    <tr><td>
  <![endif]-->

  <!-- MAIN CONTAINER -->
  <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;padding:40px 20px;" class="container">
    <tr>
      <td align="center" style="background:#ffffff;border-radius:24px;padding:44px 44px 32px;border-top:5px solid #2563eb;box-shadow:0 20px 60px -12px rgba(0,20,60,0.12);" class="dark-card">
        
        <!-- ===== PREHEADER ===== -->
        <div style="display:none;font-size:1px;color:#ffffff;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
          Your verification code is {{ $otp }}. Use this to complete your action. This code expires in  10 minutes.
        </div>

        <!-- ===== BRAND HEADER ===== -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td align="center" style="padding-bottom:24px;">
              <!-- Brand Name -->
              <div style="font-size:26px;font-weight:700;color:#0f172a;letter-spacing:-0.5px;" class="dark-text-primary brand-text">
                <span style="color:#2563eb;">Dear</span> Ba'bs
              </div>
              <div style="color:#64748b;font-size:13px;font-weight:400;letter-spacing:0.5px;margin-top:2px;" class="dark-text-secondary">
                Fastfood &amp; Catering Services
              </div>
              
              <!-- Decorative Divider -->
              <div style="height:2px;background:linear-gradient(to right,transparent,#e2e8f0,transparent);margin:16px auto 12px;width:60px;" class="dark-divider"></div>
              
              <!-- Security Badge with formal lock icon -->
              <div style="display:inline-flex;align-items:center;gap:8px;background:#eff6ff;color:#2563eb;font-size:11px;font-weight:600;padding:5px 18px;border-radius:20px;letter-spacing:0.8px;text-transform:uppercase;border:1px solid #dbeafe;" class="dark-badge">
                <!-- Lock icon (formal) -->
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:inline-block;vertical-align:middle;">
                  <rect x="5" y="11" width="14" height="11" rx="2" stroke="currentColor" stroke-width="1.8"/>
                  <path d="M8 11V8C8 5.79086 9.79086 4 12 4C14.2091 4 16 5.79086 16 8V11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                  <circle cx="12" cy="16" r="1.5" fill="currentColor"/>
                  <line x1="12" y1="17.5" x2="12" y2="19.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                Secure Verification
              </div>
            </td>
          </tr>
        </table>

        <!-- ===== GREETING ===== -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="padding-bottom:20px;text-align:left;">
              <h2 style="font-size:21px;font-weight:600;color:#0f172a;margin:0 0 6px 0;" class="dark-greeting greeting">
                Hello, <strong style="color:#0f172a;" class="dark-greeting-strong">{{ $name }}</strong>
              </h2>
              <p style="color:#475569;font-size:15px;line-height:1.7;margin:0;" class="dark-message">
                We received a request to verify your identity. Please use the One-Time Password (OTP) below to complete your action.
              </p>
            </td>
          </tr>
        </table>

        <!-- ===== OTP BOX ===== -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="background:#f8fafc;border-radius:16px;padding:32px 24px 28px;text-align:center;border:1px solid #eef2ff;" class="dark-otp-bg">
              <!-- Label -->
              <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:16px;" class="dark-text-muted">
                Verification Code
              </div>
              
              <!-- OTP Digits -->
              <div style="font-size:48px;font-weight:700;letter-spacing:16px;color:#0f172a;font-family:'SF Mono','Courier New',monospace;background:#ffffff;padding:16px 28px;border-radius:12px;display:inline-block;box-shadow:0 2px 12px rgba(0,0,0,0.04);border:1px solid #e2e8f0;" class="dark-otp-box otp-digits">
                <span style="color:#2563eb;">{{ $otp }}</span>
              </div>
              
              <!-- Expiry with formal clock icon -->
              <div style="display:inline-flex;align-items:center;gap:8px;color:#475569;font-size:13px;margin-top:18px;padding:8px 20px;background:#ffffff;border-radius:20px;border:1px solid #eef2ff;" class="dark-expiry">
                <!-- Clock icon -->
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:inline-block;vertical-align:middle;">
                  <circle cx="12" cy="12" r="9" stroke="#2563eb" stroke-width="1.8"/>
                  <path d="M12 7V12L15 15" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                Expires in <strong style="color:#2563eb;font-weight:600;"> 10 minutes</strong>
              </div>
            </td>
          </tr>
        </table>

        <!-- ===== INSTRUCTIONS ===== -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="padding:20px 0 12px;text-align:left;">
              <p style="color:#475569;font-size:14px;line-height:1.8;margin:0 0 4px 0;" class="dark-message">
                Enter this code on the verification page to confirm your identity and proceed with your request.
              </p>
              <p style="font-size:13px;color:#64748b;margin:6px 0 0 0;" class="dark-text-muted">
                If you did not request this code, please ignore this email or 
                <a href="#" style="color:#2563eb;font-weight:500;text-decoration:none;border-bottom:1px solid #dbeafe;">contact our support team</a> immediately.
              </p>
            </td>
          </tr>
        </table>

        <!-- ===== SECURITY NOTE with formal shield icon ===== -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="background:#f8fafc;padding:16px 20px;border-radius:12px;border-left:4px solid #2563eb;" class="dark-security">
              <table cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                  <td style="padding-right:14px;vertical-align:middle;width:36px;">
                    <!-- Shield icon -->
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:block;color:#2563eb;">
                      <path d="M12 3L5 6V11C5 16.5 12 21 12 21C12 21 19 16.5 19 11V6L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                      <path d="M12 8V12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                      <circle cx="12" cy="15" r="0.8" fill="currentColor"/>
                    </svg>
                  </td>
                  <td style="vertical-align:middle;">
                    <p style="font-size:13px;color:#475569;line-height:1.6;margin:0;" class="dark-security-text">
                      <strong style="color:#0f172a;" class="dark-text-primary">Security Tip:</strong> Never share this OTP with anyone. 
                      Our team will <strong style="color:#2563eb;">never</strong> ask for your verification code.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <!-- ===== FOOTER ===== -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="text-align:center;margin-top:28px;padding-top:24px;border-top:1px solid #eef2ff;" class="dark-footer-border">
              <div style="height:1px;background:linear-gradient(to right,transparent,#e2e8f0,transparent);margin-bottom:20px;" class="dark-divider"></div>
              
              <!-- Brand Footer -->
              <div style="color:#94a3b8;font-size:12px;line-height:1.8;" class="dark-text-muted">
                <span style="font-weight:600;color:#0f172a;" class="dark-text-primary">
                  <span style="color:#2563eb;">Dear</span> Ba'bs
                </span> &nbsp;—&nbsp; All rights reserved &bull; {{ date('Y') }}
              </div>
              <div style="color:#94a3b8;font-size:11px;margin-top:2px;" class="dark-text-muted">
                This is an automated message. Please do not reply to this email.
              </div>
              
              <!-- Footer Links with formal icons - proper arrangement -->
              <div style="margin-top:14px;">
                <table cellpadding="0" cellspacing="0" border="0" align="center" class="footer-links">
                  <tr>
                    <!-- Privacy Policy -->
                    <td style="padding:0 12px;">
                      <a href="#" style="color:#94a3b8;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" class="dark-muted-link">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:inline-block;vertical-align:middle;">
                          <rect x="3" y="10" width="18" height="12" rx="2" stroke="currentColor" stroke-width="1.6"/>
                          <path d="M7 10V7C7 4.23858 9.23858 2 12 2C14.7614 2 17 4.23858 17 7V10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                          <circle cx="12" cy="15" r="1.5" fill="currentColor"/>
                          <line x1="12" y1="16.5" x2="12" y2="19" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        Privacy Policy
                      </a>
                    </td>
                    <td style="color:#e2e8f0;padding:0 4px;" class="divider">|</td>
                    
                    <!-- Terms of Service -->
                    <td style="padding:0 12px;">
                      <a href="#" style="color:#94a3b8;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" class="dark-muted-link">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:inline-block;vertical-align:middle;">
                          <path d="M4 4H20V20H4V4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                          <line x1="8" y1="8" x2="16" y2="8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                          <line x1="8" y1="12" x2="16" y2="12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                          <line x1="8" y1="16" x2="12" y2="16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        Terms of Service
                      </a>
                    </td>
                    <td style="color:#e2e8f0;padding:0 4px;" class="divider">|</td>
                    
                    <!-- Contact Support -->
                    <td style="padding:0 12px;">
                      <a href="#" style="color:#94a3b8;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" class="dark-muted-link">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:inline-block;vertical-align:middle;">
                          <rect x="2" y="4" width="20" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/>
                          <path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        Contact Support
                      </a>
                    </td>
                  </tr>
                </table>
              </div>

              <!-- Additional formal footer note with location icon -->
              <div style="margin-top:14px;color:#94a3b8;font-size:10px;letter-spacing:0.3px;display:flex;align-items:center;justify-content:center;gap:4px;" class="dark-text-muted">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="display:inline-block;vertical-align:middle;">
                  <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" stroke="currentColor" stroke-width="1.6"/>
                  <circle cx="12" cy="9" r="3" stroke="currentColor" stroke-width="1.6"/>
                </svg>
                Dear Ba'bs · 123 Culinary Avenue · Suite 200
              </div>
            </td>
          </tr>
        </table>

      </td>
    </tr>
  </table>

  <!--[if (gte mso 9)|(IE)]>
      </td></tr>
  </table>
  <![endif]-->

</body>
</html>