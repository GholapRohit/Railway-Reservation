// server.js - starts the Express API. All routes and SQL are in routes.js.
require('dotenv').config();
const express = require('express');
const cors = require('cors');
const { pool } = require('./db');
const { router, errorHandler } = require('./routes');

const app = express();
const PORT = Number(process.env.PORT) || 5000;

// Allow the React dev server (Vite, port 5173) to call this API from the browser
app.use(cors({ origin: ['http://localhost:5173', 'http://127.0.0.1:5173'] }));
app.use(express.json());

app.use('/api', router);
app.use((req, res) => res.status(404).json({ success: false, message: 'API route not found.' }));
app.use(errorHandler);

app.listen(PORT, async () => {
  console.log(`API running at http://localhost:${PORT}/api`);
  try {
    await pool.query('SELECT 1');
    console.log(`Connected to MySQL database "${process.env.DB_NAME || 'railway_reservation'}".`);
  } catch (error) {
    console.error('Could not connect to MySQL:', error.code || error.message);
    console.error('Check that MySQL is running, that schema.sql was imported and that server/.env is correct.');
  }
});
