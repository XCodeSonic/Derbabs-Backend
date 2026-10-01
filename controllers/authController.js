const User = require('../models/User');
const Otp = require('../models/Otp');
const crypto = require('crypto');
const nodemailer = require('nodemailer');
const jwt = require('jsonwebtoken');

// Email transporter configuration
const transporter = nodemailer.createTransport({
  service: 'gmail',
  auth: {
    user: process.env.EMAIL_USER,
    pass: process.env.EMAIL_PASS
  }
});

// Generate OTP
const generateOTP = () => {
  return Math.floor(100000 + Math.random() * 900000).toString();
};

// Send OTP via email
const sendOTPEmail = async (email, otp, type = 'registration') => {
  const subject = type === 'registration' 
    ? 'Dear Bab\'s - Email Verification OTP'
    : 'Dear Bab\'s - Password Reset OTP';
    
  const html = `
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="UTF-8">
      <title>${subject}</title>
      <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc3545; padding: 20px; text-align: center; color: white; border-radius: 8px 8px 0 0; }
        .content { padding: 30px; background: #f9f9f9; border-radius: 0 0 8px 8px; }
        .otp { font-size: 32px; font-weight: bold; text-align: center; padding: 20px; background: white; border-radius: 8px; margin: 20px 0; letter-spacing: 5px; color: #dc3545; }
        .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
      </style>
    </head>
    <body>
      <div class="container">
        <div class="header">
          <h1>Dear Bab's</h1>
          <p>Fast Food & Catering Services</p>
        </div>
        <div class="content">
          <h2>${type === 'registration' ? 'Email Verification' : 'Password Reset'}</h2>
          <p>Hello,</p>
          <p>${type === 'registration' 
            ? 'Thank you for registering with Dear Bab\'s! Please use the verification code below to complete your registration.' 
            : 'We received a request to reset your password. Use the verification code below to proceed.'}</p>
          <div class="otp">${otp}</div>
          <p>This code will expire in <strong>10 minutes</strong>.</p>
          <p>If you didn't request this, please ignore this email.</p>
        </div>
        <div class="footer">
          <p>&copy; ${new Date().getFullYear()} Dear Bab's. All rights reserved.</p>
        </div>
      </div>
    </body>
    </html>
  `;
  
  const mailOptions = {
    from: `"Dear Bab's" <${process.env.EMAIL_USER}>`,
    to: email,
    subject: subject,
    html: html
  };
  
  await transporter.sendMail(mailOptions);
};

// Send OTP for registration
exports.sendOTP = async (req, res) => {
  try {
    const { email, type = 'registration' } = req.body;
    
    if (!email) {
      return res.status(400).json({
        success: false,
        message: 'Email address is required'
      });
    }
    
    const trimmedEmail = email.trim().toLowerCase();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!emailRegex.test(trimmedEmail)) {
      return res.status(400).json({
        success: false,
        message: 'Please enter a valid email address'
      });
    }
    
    if (type === 'registration') {
      const existingUser = await User.findByEmailOrUserId(trimmedEmail);
      if (existingUser) {
        return res.status(400).json({
          success: false,
          message: 'Email already registered. Please login or use a different email.'
        });
      }
    }
    
    // Invalidate existing OTPs
    await Otp.invalidateAll(trimmedEmail, type);
    
    const otpCode = generateOTP();
    const expiresAt = new Date(Date.now() + 10 * 60 * 1000);
    const expiresAtFormatted = expiresAt.toISOString().slice(0, 19).replace('T', ' ');
    
    const otpId = await Otp.create({
      email: trimmedEmail,
      otp_code: otpCode,
      type: type,
      expires_at: expiresAtFormatted
    });
    
    try {
      await sendOTPEmail(trimmedEmail, otpCode, type);
    } catch (emailError) {
      console.error('Email sending failed:', emailError);
      if (process.env.NODE_ENV === 'development') {
        return res.status(200).json({
          success: true,
          message: 'OTP generated (email sending failed - check configuration)',
          data: {
            otp_id: otpId,
            debug_otp: otpCode
          }
        });
      }
      throw new Error('Failed to send verification email');
    }
    
    const responseData = {
      otp_id: otpId,
      message: 'OTP sent successfully'
    };
    
    if (process.env.NODE_ENV === 'development') {
      responseData.debug_otp = otpCode;
      console.log('📧 Development OTP:', otpCode);
    }
    
    res.status(200).json({
      success: true,
      message: 'OTP sent successfully to your email',
      data: responseData
    });
    
  } catch (error) {
    console.error('Send OTP error:', error);
    res.status(500).json({
      success: false,
      message: error.message || 'Failed to send OTP. Please try again.'
    });
  }
};

// Verify OTP
exports.verifyOTP = async (req, res) => {
  try {
    const { email, otp_code, type = 'registration' } = req.body;
    
    if (!email || !otp_code) {
      return res.status(400).json({
        success: false,
        message: 'Email and OTP code are required'
      });
    }
    
    const trimmedEmail = email.trim().toLowerCase();
    
    const otpRecord = await Otp.findValidOtp(trimmedEmail, otp_code, type);
    
    if (!otpRecord) {
      return res.status(400).json({
        success: false,
        message: 'Invalid or expired OTP. Please request a new one.'
      });
    }
    
    await Otp.markAsUsed(otpRecord.id);
    
    const verificationToken = crypto.randomBytes(32).toString('hex');
    
    res.status(200).json({
      success: true,
      message: 'OTP verified successfully',
      data: {
        verification_token: verificationToken
      }
    });
    
  } catch (error) {
    console.error('Verify OTP error:', error);
    res.status(500).json({
      success: false,
      message: 'Failed to verify OTP'
    });
  }
};

// Resend OTP
exports.resendOTP = async (req, res) => {
  try {
    const { email, type = 'registration' } = req.body;
    
    if (!email) {
      return res.status(400).json({
        success: false,
        message: 'Email address is required'
      });
    }
    
    const trimmedEmail = email.trim().toLowerCase();
    
    if (type === 'registration') {
      const existingUser = await User.findByEmailOrUserId(trimmedEmail);
      if (existingUser) {
        return res.status(400).json({
          success: false,
          message: 'Email already registered.'
        });
      }
    }
    
    await Otp.invalidateAll(trimmedEmail, type);
    
    const otpCode = generateOTP();
    const expiresAt = new Date(Date.now() + 10 * 60 * 1000);
    const expiresAtFormatted = expiresAt.toISOString().slice(0, 19).replace('T', ' ');
    
    const otpId = await Otp.create({
      email: trimmedEmail,
      otp_code: otpCode,
      type: type,
      expires_at: expiresAtFormatted
    });
    
    await sendOTPEmail(trimmedEmail, otpCode, type);
    
    const responseData = {
      otp_id: otpId,
      message: 'OTP resent successfully'
    };
    
    if (process.env.NODE_ENV === 'development') {
      responseData.debug_otp = otpCode;
    }
    
    res.status(200).json({
      success: true,
      message: 'OTP resent successfully',
      data: responseData
    });
    
  } catch (error) {
    console.error('Resend OTP error:', error);
    res.status(500).json({
      success: false,
      message: 'Failed to resend OTP'
    });
  }
};

// Register User
exports.register = async (req, res) => {
  try {
    const {
      full_name,
      email,
      user_id,
      password,
      password_confirmation,
      verification_token
    } = req.body;
    
    if (!full_name || !email || !user_id || !password || !password_confirmation) {
      return res.status(400).json({
        success: false,
        message: 'All fields are required'
      });
    }
    
    if (password !== password_confirmation) {
      return res.status(400).json({
        success: false,
        message: 'Passwords do not match'
      });
    }
    
    if (password.length < 8) {
      return res.status(400).json({
        success: false,
        message: 'Password must be at least 8 characters'
      });
    }
    
    if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(password)) {
      return res.status(400).json({
        success: false,
        message: 'Password must contain uppercase, lowercase and numbers'
      });
    }
    
    const existingUser = await User.findByEmailOrUserId(email);
    if (existingUser) {
      return res.status(400).json({
        success: false,
        message: 'User already exists with this email or user ID'
      });
    }
    
    if (!verification_token) {
      return res.status(400).json({
        success: false,
        message: 'Email verification is required'
      });
    }
    
    const userId = await User.create({
      full_name,
      email: email.toLowerCase(),
      user_id,
      password,
      email_verified: true
    });
    
    const user = await User.findById(userId);
    
    const token = jwt.sign(
      { 
        id: user.id, 
        user_id: user.user_id, 
        email: user.email,
        full_name: user.full_name
      },
      process.env.JWT_SECRET,
      { expiresIn: '7d' }
    );
    
    res.status(201).json({
      success: true,
      message: 'User registered successfully',
      data: {
        token,
        user
      }
    });
    
  } catch (error) {
    console.error('Registration error:', error);
    res.status(500).json({
      success: false,
      message: 'Registration failed'
    });
  }
};

// Login User
exports.login = async (req, res) => {
  try {
    const { userId, password, remember_me } = req.body;
    
    if (!userId || !password) {
      return res.status(400).json({
        success: false,
        message: 'User ID/Email and password are required'
      });
    }
    
    const user = await User.findByEmailOrUserId(userId);
    
    if (!user) {
      return res.status(401).json({
        success: false,
        message: 'Invalid credentials'
      });
    }
    
    if (!user.is_active) {
      return res.status(403).json({
        success: false,
        message: 'Your account is not active. Please contact support.'
      });
    }
    
    const isPasswordValid = await User.verifyPassword(password, user.password);
    if (!isPasswordValid) {
      return res.status(401).json({
        success: false,
        message: 'Invalid credentials'
      });
    }
    
    await User.updateLastLogin(user.id);
    
    const expiresIn = remember_me ? '30d' : '7d';
    
    const token = jwt.sign(
      { 
        id: user.id, 
        user_id: user.user_id, 
        email: user.email,
        full_name: user.full_name
      },
      process.env.JWT_SECRET,
      { expiresIn: expiresIn }
    );
    
    const userData = {
      id: user.id,
      full_name: user.full_name,
      email: user.email,
      user_id: user.user_id
    };
    
    res.status(200).json({
      success: true,
      message: 'Login successful',
      data: {
        token,
        user: userData
      }
    });
    
  } catch (error) {
    console.error('Login error:', error);
    res.status(500).json({
      success: false,
      message: 'Login failed'
    });
  }
};

// Forgot Password
exports.forgotPassword = async (req, res) => {
  try {
    const { user_id } = req.body;
    
    if (!user_id) {
      return res.status(400).json({
        success: false,
        message: 'User ID or Email is required'
      });
    }
    
    const user = await User.findByEmailOrUserId(user_id);
    
    if (!user) {
      return res.status(404).json({
        success: false,
        message: 'User not found'
      });
    }
    
    await Otp.invalidateAll(user.email, 'password_reset');
    
    const otpCode = generateOTP();
    const expiresAt = new Date(Date.now() + 10 * 60 * 1000);
    const expiresAtFormatted = expiresAt.toISOString().slice(0, 19).replace('T', ' ');
    
    const otpId = await Otp.create({
      email: user.email,
      otp_code: otpCode,
      type: 'password_reset',
      expires_at: expiresAtFormatted
    });
    
    await sendOTPEmail(user.email, otpCode, 'password_reset');
    
    const responseData = {
      otp_id: otpId,
      message: 'Password reset OTP sent successfully'
    };
    
    if (process.env.NODE_ENV === 'development') {
      responseData.debug_otp = otpCode;
    }
    
    res.status(200).json({
      success: true,
      message: 'Password reset code sent to your email',
      data: responseData
    });
    
  } catch (error) {
    console.error('Forgot password error:', error);
    res.status(500).json({
      success: false,
      message: 'Failed to process request'
    });
  }
};

// Verify Reset OTP
exports.verifyResetOTP = async (req, res) => {
  try {
    const { user_id, otp_code } = req.body;
    
    if (!user_id || !otp_code) {
      return res.status(400).json({
        success: false,
        message: 'User ID and OTP code are required'
      });
    }
    
    const user = await User.findByEmailOrUserId(user_id);
    
    if (!user) {
      return res.status(404).json({
        success: false,
        message: 'User not found'
      });
    }
    
    const otpRecord = await Otp.findValidOtp(user.email, otp_code, 'password_reset');
    
    if (!otpRecord) {
      return res.status(400).json({
        success: false,
        message: 'Invalid or expired OTP'
      });
    }
    
    await Otp.markAsUsed(otpRecord.id);
    
    const resetToken = crypto.randomBytes(32).toString('hex');
    const expiresAt = new Date(Date.now() + 60 * 60 * 1000);
    const expiresAtFormatted = expiresAt.toISOString().slice(0, 19).replace('T', ' ');
    
    await User.setResetToken(user.id, resetToken, expiresAtFormatted);
    
    res.status(200).json({
      success: true,
      message: 'OTP verified successfully',
      data: {
        reset_token: resetToken
      }
    });
    
  } catch (error) {
    console.error('Verify reset OTP error:', error);
    res.status(500).json({
      success: false,
      message: 'Failed to verify OTP'
    });
  }
};

// Resend Reset OTP
exports.resendResetOTP = async (req, res) => {
  try {
    const { user_id } = req.body;
    
    if (!user_id) {
      return res.status(400).json({
        success: false,
        message: 'User ID is required'
      });
    }
    
    const user = await User.findByEmailOrUserId(user_id);
    
    if (!user) {
      return res.status(404).json({
        success: false,
        message: 'User not found'
      });
    }
    
    await Otp.invalidateAll(user.email, 'password_reset');
    
    const otpCode = generateOTP();
    const expiresAt = new Date(Date.now() + 10 * 60 * 1000);
    const expiresAtFormatted = expiresAt.toISOString().slice(0, 19).replace('T', ' ');
    
    const otpId = await Otp.create({
      email: user.email,
      otp_code: otpCode,
      type: 'password_reset',
      expires_at: expiresAtFormatted
    });
    
    await sendOTPEmail(user.email, otpCode, 'password_reset');
    
    const responseData = {
      otp_id: otpId,
      message: 'OTP resent successfully'
    };
    
    if (process.env.NODE_ENV === 'development') {
      responseData.debug_otp = otpCode;
    }
    
    res.status(200).json({
      success: true,
      message: 'OTP resent successfully',
      data: responseData
    });
    
  } catch (error) {
    console.error('Resend reset OTP error:', error);
    res.status(500).json({
      success: false,
      message: 'Failed to resend OTP'
    });
  }
};

// Reset Password
exports.resetPassword = async (req, res) => {
  try {
    const { user_id, new_password, password_confirmation, reset_token } = req.body;
    
    if (!user_id || !new_password || !password_confirmation || !reset_token) {
      return res.status(400).json({
        success: false,
        message: 'All fields are required'
      });
    }
    
    if (new_password !== password_confirmation) {
      return res.status(400).json({
        success: false,
        message: 'Passwords do not match'
      });
    }
    
    if (new_password.length < 8) {
      return res.status(400).json({
        success: false,
        message: 'Password must be at least 8 characters'
      });
    }
    
    const user = await User.findByEmailOrUserId(user_id);
    
    if (!user) {
      return res.status(404).json({
        success: false,
        message: 'User not found'
      });
    }
    
    if (user.reset_password_token !== reset_token ||
        new Date(user.reset_password_expires) < new Date()) {
      return res.status(400).json({
        success: false,
        message: 'Invalid or expired reset token'
      });
    }
    
    await User.updatePassword(user.id, new_password);
    await User.clearResetToken(user.id);
    
    res.status(200).json({
      success: true,
      message: 'Password reset successfully'
    });
    
  } catch (error) {
    console.error('Reset password error:', error);
    res.status(500).json({
      success: false,
      message: 'Failed to reset password'
    });
  }
};

// Get Current User
exports.getCurrentUser = async (req, res) => {
  try {
    const user = await User.findById(req.user.id);
    if (!user) {
      return res.status(404).json({
        success: false,
        message: 'User not found'
      });
    }
    
    res.status(200).json({
      success: true,
      data: { user }
    });
    
  } catch (error) {
    console.error('Get user error:', error);
    res.status(500).json({
      success: false,
      message: 'Failed to get user'
    });
  }
};