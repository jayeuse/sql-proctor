import { test as base, expect } from "@playwright/test";
import path from "node:path";

const AUTH_DIR = path.resolve(import.meta.dirname, "../.auth");

function attachGuards(page) {
  const pageErrors = [];
  const serverErrors = [];
  const csrfErrors = [];

  page.on("pageerror", (err) => {
    pageErrors.push(err.message);
  });

  page.on("response", (response) => {
    const status = response.status();
    if (status >= 500) {
      serverErrors.push(`${status} ${response.url()}`);
    }
    if (status === 419) {
      csrfErrors.push(`419 ${response.url()}`);
    }
  });

  return {
    assertClean() {
      expect(pageErrors, "Uncaught page errors detected").toEqual([]);
      expect(serverErrors, "HTTP 5xx responses detected").toEqual([]);
      expect(csrfErrors, "Unexpected 419 CSRF responses detected").toEqual([]);
    },
  };
}

export const test = base.extend({
  role: ["examiner", { option: true }],

  page: async ({ page }, provide) => {
    const guards = attachGuards(page);
    await provide(page);
    guards.assertClean();
  },

  authedPage: async ({ browser, role }, provide) => {
    const context = await browser.newContext({
      storageState: path.join(AUTH_DIR, `${role}.json`),
    });
    const page = await context.newPage();
    const guards = attachGuards(page);
    await provide(page);
    guards.assertClean();
    await context.close();
  },
});

export { expect };
