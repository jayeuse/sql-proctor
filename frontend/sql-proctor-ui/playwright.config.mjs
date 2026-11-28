import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/e2e",
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: Boolean(process.env.CI),
  reporter: [["list"], ["html", { open: "never" }]],
  use: {
    baseURL: "http://localhost:3000",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },
  webServer: [
    {
      command:
        'cd ../../backend/sql-proctor-api && PATH="/opt/homebrew/bin:$PATH" DB_CONNECTION=pgsql DB_DATABASE=sql_proctor_test php artisan serve --host=localhost --port=8000',
      url: "http://localhost:8000/up",
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: "npm run build && npm run start -- -H localhost -p 3000",
      url: "http://localhost:3000",
      reuseExistingServer: false,
      timeout: 120_000,
      env: {
        NEXT_PUBLIC_API_URL: "http://localhost:8000/api",
      },
    },
  ],
  projects: [
    {
      name: "auth-setup",
      testMatch: /auth\/auth\.setup\.js$/,
    },
    {
      name: "chromium-desktop",
      dependencies: ["auth-setup"],
      testMatch: /specs\/(auth|admin|examiner|student)\/.*\.spec\.js$/,
      use: {
        browserName: "chromium",
        viewport: { width: 1280, height: 800 },
      },
    },
    {
      name: "chromium-mobile",
      dependencies: ["auth-setup"],
      testMatch: /specs\/ui\/.*\.spec\.js$/,
      use: {
        browserName: "chromium",
        viewport: { width: 375, height: 812 },
        isMobile: true,
        hasTouch: true,
      },
    },
  ],
});
