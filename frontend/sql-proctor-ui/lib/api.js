import axios from "axios";

const baseURL = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api";
const apiOrigin = baseURL.replace(/\/api\/?$/, "");

// Single Axios instance for the Laravel Sanctum SPA API.
const api = axios.create({
  baseURL,
  timeout: 15000,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: "XSRF-TOKEN",
  xsrfHeaderName: "X-XSRF-TOKEN",
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
  },
});

export async function ensureCsrfCookie() {
  await api.get("/sanctum/csrf-cookie", {
    baseURL: apiOrigin,
  });
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const config = error?.config;
    if (
      error?.response?.status === 419 &&
      config &&
      !config._csrfRetried &&
      !String(config.url || "").includes("/sanctum/csrf-cookie")
    ) {
      config._csrfRetried = true;
      await ensureCsrfCookie();
      return api(config);
    }

    return Promise.reject(error);
  },
);

export function extractApiError(error, fallback = "An unexpected error occurred.") {
  const status = error?.response?.status ?? null;
  const data = error?.response?.data;
  const fieldErrors = data?.errors && typeof data.errors === "object" ? data.errors : null;

  let firstFieldMessage = null;
  if (fieldErrors) {
    for (const messages of Object.values(fieldErrors)) {
      if (Array.isArray(messages) && messages.length > 0) {
        firstFieldMessage = String(messages[0]);
        break;
      }
    }
  }

  return {
    status,
    code: data?.code ?? null,
    message: firstFieldMessage || data?.message || fallback,
    errors: fieldErrors,
  };
}

export default api;
