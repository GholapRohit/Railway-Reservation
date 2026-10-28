// db.js - MySQL connection pool and a helper that runs work inside ONE transaction.
require('dotenv').config();
const mysql = require('mysql2/promise');

const pool = mysql.createPool({
  host: process.env.DB_HOST || 'localhost',
  port: Number(process.env.DB_PORT) || 3306,
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'railway_reservation',
  waitForConnections: true,
  connectionLimit: 10,
  dateStrings: true,     // DATE / DATETIME columns come back as plain text like "2026-11-01"
  decimalNumbers: true,  // DECIMAL columns (money) come back as numbers, not text
});

// Runs work(connection) inside a transaction:
//   START TRANSACTION -> work -> COMMIT, or ROLLBACK (undo everything) if work throws.
// Every query of the transaction must use the `connection` passed to work().
async function withTransaction(work) {
  const connection = await pool.getConnection();
  try {
    await connection.beginTransaction();
    const result = await work(connection);
    await connection.commit();
    return result;
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

module.exports = { pool, withTransaction };
