// api.js - one axios instance for the whole app. Every API response is { success, message, data }.
import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:5000/api',
});

// Message to show in an alert: the server's own message, or a hint when the server is unreachable.
export function errorMessage(error) {
  return error?.response?.data?.message || 'Cannot reach the server. Is the API running on port 5000?';
}

// Today as YYYY-MM-DD in the browser's local time (used as the minimum of date inputs)
export function today() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default api;
