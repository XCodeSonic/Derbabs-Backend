const { promisePool } = require('../config/database');

class Otp {
  // Create OTP
  static async create(otpData) {
    const { email, otp_code, type, expires_at } = otpData;
    
    const [result] = await promisePool.execute(
      `INSERT INTO otps (email, otp_code, type, expires_at, is_used) 
       VALUES (?, ?, ?, ?, ?)`,
      [email, otp_code, type, expires_at, false]
    );
    
    return result.insertId;
  }
  
  // Find valid OTP
  static async findValidOtp(email, otp_code, type) {
    const [rows] = await promisePool.execute(
      `SELECT * FROM otps 
       WHERE email = ? AND otp_code = ? AND type = ? AND is_used = false AND expires_at > NOW()`,
      [email, otp_code, type]
    );
    return rows[0];
  }
  
  // Mark OTP as used
  static async markAsUsed(id) {
    const [result] = await promisePool.execute(
      `UPDATE otps SET is_used = true WHERE id = ?`,
      [id]
    );
    return result.affectedRows > 0;
  }
  
  // Invalidate all unused OTPs for email and type
  static async invalidateAll(email, type) {
    const [result] = await promisePool.execute(
      `UPDATE otps SET is_used = true WHERE email = ? AND type = ? AND is_used = false`,
      [email, type]
    );
    return result.affectedRows;
  }
  
  // Clean expired OTPs
  static async cleanExpired() {
    const [result] = await promisePool.execute(
      `DELETE FROM otps WHERE expires_at < NOW()`
    );
    return result.affectedRows;
  }
}

module.exports = Otp;