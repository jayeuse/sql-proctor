import { test, expect } from "../../fixtures/test.js";

test.describe("Mobile viewport (375x812) responsive layout verification", () => {
  test("public join and staff login fit phone viewport without horizontal overflow", async ({ page }) => {
    for (const route of ["/", "/login"]) {
      await page.goto(route);
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth,
      );
      expect(overflow, `Horizontal overflow on ${route} at 375px`).toBe(false);
    }
  });

  test.describe("admin mobile", () => {
    test.use({ role: "admin" });

    test("admin console fits phone viewport without page overflow", async ({ authedPage: page }) => {
      await page.goto("/admin");
      await expect(page.getByRole("heading", { name: "Administration console" })).toBeVisible();
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth,
      );
      expect(overflow, "Horizontal overflow on /admin at 375px").toBe(false);
    });
  });

  test.describe("examiner mobile", () => {
    test.use({ role: "examiner" });

    test("examiner console fits phone viewport without page overflow", async ({ authedPage: page }) => {
      await page.goto("/examiner");
      await expect(page.getByRole("heading", { name: "Examiner console" })).toBeVisible();
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth,
      );
      expect(overflow, "Horizontal overflow on /examiner at 375px").toBe(false);
    });
  });
});
