<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmation</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f8fafc;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            padding: 30px 40px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            font-size: 28px;
            margin: 0;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .header p {
            color: rgba(255,255,255,0.8);
            margin: 8px 0 0;
            font-size: 14px;
        }
        .content {
            padding: 30px 40px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 16px;
        }
        .message {
            color: #475569;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .details {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #e2e8f0;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
        }
        .detail-value {
            color: #1e293b;
            font-size: 13px;
            font-weight: 600;
        }
        .total {
            background: #eff6ff;
            padding: 16px 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 24px;
        }
        .total-label {
            color: #3b82f6;
            font-size: 13px;
            font-weight: 500;
        }
        .total-amount {
            color: #1e293b;
            font-size: 24px;
            font-weight: 700;
        }
        .button {
            display: inline-block;
            background: #3b82f6;
            color: #ffffff;
            padding: 12px 32px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
        .button:hover {
            background: #2563eb;
        }
        .footer {
            text-align: center;
            padding: 20px 40px;
            border-top: 1px solid #e2e8f0;
            color: #94a3b8;
            font-size: 12px;
        }
        .footer a {
            color: #3b82f6;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🎉 Booking Confirmed!</h1>
            <p>Dear Bab's Catering - Thank you for choosing us</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">Dear {{ $customerName }},</div>
            <div class="message">
                We are delighted to confirm your booking with <strong>Dear Bab's Catering</strong>.
                Your event has been successfully scheduled and we look forward to serving you!
            </div>

            <!-- Booking Details -->
            <div class="details">
                <div class="detail-row">
                    <span class="detail-label">Booking Reference</span>
                    <span class="detail-value">{{ $bookingNo }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Event Date</span>
                    <span class="detail-value">{{ $eventDate }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Event Time</span>
                    <span class="detail-value">{{ $eventTime }}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Venue</span>
                    <span class="detail-value">{{ $venue }}</span>
                </div>
            </div>

            <!-- Total Amount -->
            <div class="total">
                <div class="total-label">Total Amount</div>
                <div class="total-amount">₱{{ $totalAmount }}</div>
            </div>

            <!-- Action Button -->
            <div style="text-align: center;">
                <a href="{{ url('/customer/bookings/' . $bookingId) }}" class="button">View Your Booking</a>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Dear Bab's Catering — Creating unforgettable events</p>
            <p>📍 123 Main Street, City | 📞 (02) 123-4567 | ✉️ hello@dearbabs.com</p>
            <p style="margin-top: 8px; font-size: 11px; color: #cbd5e1;">
                This is an automated confirmation. If you have any questions, please contact our team.
            </p>
        </div>
    </div>
</body>
</html>