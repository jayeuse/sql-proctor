import { test, expect } from "../../fixtures/test.js";
import { clearTestRateLimits } from "../../helpers/backend.mjs";

test.describe("Staff authentication and lockout flows", () => {
  test.beforeEach(() => {
    clearTestRateLimits();
  });

  test("enforces client validation, generic 401 on wrong/inactive credentials, 429 lockout, and login/logout session lifecycle", async ({
    browser,
    page,
  }) => {
    await page.goto("/login");
    await expect(page.getByRole("heading", { name: "Staff sign in" })).toBeVisible();

    // Verify empty submit stays on /login with accessible error
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(page.locator('main [role="alert"]')).toHaveText("Enter your username and password.");

    // Keyboard navigation focus check
    await page.getByLabel("Username").focus();
    await expect(page.getByLabel("Username")).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(page.getByLabel("Password")).toBeFocused();

    // Unknown user and inactive user show identical generic 401 message
    await page.getByLabel("Username").fill("nonexistent_qa");
    await page.getByLabel("Password").fill("WrongPassword123!");
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(page.locator('main [role="alert"]')).toHaveText("Invalid username or password.");

    await page.getByLabel("Username").fill("e2e_inactive");
    await page.getByLabel("Password").fill("InactivePass123!");
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(page.locator('main [role="alert"]')).toHaveText("Invalid username or password.");

    // Rate limit 5 failed attempts -> 6th triggers 429 lockout
    for (let i = 0; i < 5; i += 1) {
      await page.getByLabel("Username").fill("lockout_probe");
      await page.getByLabel("Password").fill("WrongPassword123!");
      await page.getByRole("button", { name: "Sign in" }).click();
      await expect(page.locator('main [role="alert"]')).toHaveText("Invalid username or password.");
    }

    await page.getByLabel("Username").fill("lockout_probe");
    await page.getByLabel("Password").fill("WrongPassword123!");
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(page.locator('main [role="alert"]')).toContainText("Too many login attempts.");

    // Valid examiner login rotates session and redirects to /examiner, then logout invalidates session
    const examinerContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const examinerPage = await examinerContext.newPage();

    await examinerPage.goto("/login");
    await examinerPage.getByLabel("Username").fill("e2e_examiner");
    await examinerPage.getByLabel("Password").fill("E2eExaminerPass123!");
    await examinerPage.getByRole("button", { name: "Sign in" }).click();
    await examinerPage.waitForURL("**/examiner");
    await expect(examinerPage.getByText("@e2e_examiner")).toBeVisible();

    // Staff session cookie is httpOnly and hidden from JavaScript
    const cookies = await examinerContext.cookies();
    const sessionCookie = cookies.find((c) => c.name.endsWith("-session"));
    expect(sessionCookie).toBeTruthy();
    expect(sessionCookie.httpOnly).toBe(true);
    expect(sessionCookie.sameSite).toBe("Lax");

    const jsCookies = await examinerPage.evaluate(() => document.cookie);
    expect(jsCookies).not.toContain(sessionCookie.name);

    // Sign out invalidates session cleanly
    await examinerPage.getByRole("button", { name: "Sign out" }).click();
    await examinerPage.waitForURL("**/login");

    await examinerPage.goto("/examiner");
    await examinerPage.waitForURL("**/login");
    await examinerContext.close();
  });

  test("unauthenticated visitors and examiners are blocked from admin routes", async ({
    browser,
    page,
  }) => {
    await page.goto("/admin");
    await page.waitForURL("**/login");

    await page.goto("/examiner");
    await page.waitForURL("**/login");

    // Examiner visiting /admin is redirected back to /examiner
    const examinerContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const examinerPage = await examinerContext.newPage();
    await examinerPage.goto("/login");
    await examinerPage.getByLabel("Username").fill("e2e_examiner");
    await examinerPage.getByLabel("Password").fill("E2eExaminerPass123!");
    await examinerPage.getByRole("button", { name: "Sign in" }).click();
    await examinerPage.waitForURL("**/examiner");

    await examinerPage.goto("/admin");
    await examinerPage.waitForURL("**/examiner");
    await examinerContext.close();
  });
});
