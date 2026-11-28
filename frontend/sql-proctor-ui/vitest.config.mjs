import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  test: {
    include: ["__tests__/**/*.test.{js,jsx}"],
    environment: "jsdom",
    environmentOptions: {
      jsdom: {
        url: "http://localhost:8000",
      },
    },
    setupFiles: ["./vitest.setup.js"],
    env: {
      NEXT_PUBLIC_API_URL: "http://localhost:8000/api",
    },
  },
});
