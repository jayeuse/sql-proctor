import { test, expect } from "../../fixtures/test.js";

test.describe("Admin examiner management, RBAC, and audit logs", () => {
  test.use({ role: "admin" });

  test("creates examiner with generated password, filters examiners, switches cleanly to Audit Logs, and redirects from /examiner", async ({
    authedPage: page,
  }) => {
    await page.goto("/admin");
    await expect(page.getByRole("heading", { name: "Administration console" })).toBeVisible();

    // Admin navigating to /examiner redirects back to /admin
    await page.goto("/examiner");
    await page.waitForURL("**/admin");

    // Create examiner with blank password -> generated one-time password appears
    const genUsername = "e2e_gen_prof";
    await page.getByLabel("Examiner username").fill(genUsername);
    await page.getByLabel("Display name (optional)").fill("Generated Prof");
    await page.getByRole("button", { name: "Create examiner" }).click();

    await expect(page.getByText(`One-time password for ${genUsername}`)).toBeVisible();
    await expect(page.getByRole("cell", { name: genUsername, exact: true })).toBeVisible();

    // Filter examiners by username and status
    await page.getByLabel("Filter by username").fill("gen_prof");
    await page.getByRole("button", { name: "Filter", exact: true }).click();
    await expect(page.getByRole("cell", { name: genUsername, exact: true })).toBeVisible();

    await page.getByLabel("Filter by status").selectOption("inactive");
    await expect(page.getByText("No examiner accounts found.")).toBeVisible();

    await page.getByLabel("Filter by username").fill("");
    await page.getByLabel("Filter by status").selectOption("all");
    await expect(page.getByRole("cell", { name: "e2e_examiner", exact: true })).toBeVisible();

    // Switching to Audit Logs clears sensitive one-time password banner and queries PostgreSQL audit logs
    await page.getByRole("tab", { name: "Audit Logs" }).click();
    await expect(page.getByText(`One-time password for ${genUsername}`)).not.toBeVisible();
    await expect(page.getByRole("cell", { name: "examiner.created" }).first()).toBeVisible();

    await page.getByLabel("Event type filter").fill("examiner.created");
    await page.getByRole("button", { name: "Apply", exact: true }).click();
    await expect(page.getByRole("cell", { name: "examiner.created" }).first()).toBeVisible();

    await page.getByRole("button", { name: "Reset", exact: true }).click();
    await expect(page.getByLabel("Event type filter")).toHaveValue("");
  });

  test("deactivating and resetting an examiner password immediately revokes their live browser session", async ({
    browser,
    authedPage: adminPage,
  }) => {
    await adminPage.goto("/admin");
    await adminPage.getByLabel("Examiner username").fill("e2e_revoke_prof");
    await adminPage.getByLabel("Password (optional, min 12 chars)").fill("InitialExaminer123!");
    await adminPage.getByRole("button", { name: "Create examiner" }).click();
    await expect(adminPage.getByRole("cell", { name: "e2e_revoke_prof", exact: true })).toBeVisible();

    // Examiner signs in using an isolated browser context
    const exContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const exPage = await exContext.newPage();
    await exPage.goto("/login");
    await exPage.getByLabel("Username").fill("e2e_revoke_prof");
    await exPage.getByLabel("Password").fill("InitialExaminer123!");
    await exPage.getByRole("button", { name: "Sign in" }).click();
    await exPage.waitForURL("**/examiner");
    await expect(exPage.getByRole("heading", { name: "Examiner console" })).toBeVisible();

    // Admin deactivates e2e_revoke_prof
    await adminPage.getByRole("button", { name: "Deactivate e2e_revoke_prof", exact: true }).click();
    await expect(adminPage.getByRole("button", { name: "Activate e2e_revoke_prof", exact: true })).toBeVisible();

    // Live examiner action immediately gets 401 and redirects to /login
    await exPage.getByLabel("Classroom name").fill("Blocked Class");
    await exPage.getByRole("button", { name: "Create classroom" }).click();
    await exPage.waitForURL("**/login");

    // Admin reactivates and examiner signs in again, then resets password
    await adminPage.getByRole("button", { name: "Activate e2e_revoke_prof", exact: true }).click();
    await expect(adminPage.getByRole("button", { name: "Deactivate e2e_revoke_prof", exact: true })).toBeVisible();

    await exPage.getByLabel("Username").fill("e2e_revoke_prof");
    await exPage.getByLabel("Password").fill("InitialExaminer123!");
    await exPage.getByRole("button", { name: "Sign in" }).click();
    await exPage.waitForURL("**/examiner");

    await adminPage.getByRole("button", { name: "Reset password for e2e_revoke_prof" }).click();
    await expect(adminPage.getByText("One-time password for e2e_revoke_prof (password reset):")).toBeVisible();

    await exPage.reload();
    await exPage.waitForURL("**/login");
    await exContext.close();
  });
});
