import axios from "axios";

// Single Axios instance for the Laravel API.
// Override the base URL with NEXT_PUBLIC_API_URL (see .env.local.example).
const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api",
  timeout: 15000,
  withCredentials: true,
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
  },
});

export default api;
