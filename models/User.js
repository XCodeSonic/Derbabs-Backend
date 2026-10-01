const { promisePool } = require('../config/database');
const bcrypt = require('bcryptjs');

class User {
  // Create user
  static async create(userData) {
    const { full_name, email, user_id, password, email_verified = false } = userData;
    const hashedPassword = await bcrypt.hash(password, 10);
    
    const [result] = await promisePool.execute(
      `INSERT INTO users (full_name, email, user_id, password, email_verified) 
       VALUES (?, ?, ?, ?, ?)`,
      [full_name, email.toLowerCase(), user_id, hashedPassword, email_verified]
    );
    
    return result.insertId;
  }
  
  // Find user by email or user_id
  static async findByEmailOrUserId(identifier) {
    const [rows] = await promisePool.execute(
      `SELECT * FROM users WHERE email = ? OR user_id = ?`,
      [identifier.toLowerCase(), identifier]
    );
    return rows[0];
  }
  
  // Find user by ID
  static async findById(id) {
    const [rows] = await promisePool.execute(
      `SELECT id, full_name, email, user_id, is_active, email_verified, last_login, created_at 
       FROM users WHERE id = ?`,
      [id]
    );
    return rows[0];
  }
  
  // Update user
  static async update(id, updateData) {
    const fields = [];
    const values = [];
    
    for (const [key, value] of Object.entries(updateData)) {
      if (value !== undefined) {
        fields.push(`${key} = ?`);
        values.push(value);
      }
    }
    
    if (fields.length === 0) return false;
    
    values.push(id);
    const [result] = await promisePool.execute(
      `UPDATE users SET ${fields.join(', ')} WHERE id = ?`,
      values
    );
    
    return result.affectedRows > 0;
  }
  
  // Update last login
  static async updateLastLogin(id) {
    const [result] = await promisePool.execute(
      `UPDATE users SET last_login = NOW() WHERE id = ?`,
      [id]
    );
    return result.affectedRows > 0;
  }
  
  // Set reset password token
  static async setResetToken(id, token, expiresAt) {
    const [result] = await promisePool.execute(
      `UPDATE users SET reset_password_token = ?, reset_password_expires = ? WHERE id = ?`,
      [token, expiresAt, id]
    );
    return result.affectedRows > 0;
  }
  
  // Clear reset token
  static async clearResetToken(id) {
    const [result] = await promisePool.execute(
      `UPDATE users SET reset_password_token = NULL, reset_password_expires = NULL WHERE id = ?`,
      [id]
    );
    return result.affectedRows > 0;
  }
  
  // Update password
  static async updatePassword(id, newPassword) {
    const hashedPassword = await bcrypt.hash(newPassword, 10);
    const [result] = await promisePool.execute(
      `UPDATE users SET password = ? WHERE id = ?`,
      [hashedPassword, id]
    );
    return result.affectedRows > 0;
  }
  
  // Verify password
  static async verifyPassword(password, hashedPassword) {
    return await bcrypt.compare(password, hashedPassword);
  }
}

module.exports = User;