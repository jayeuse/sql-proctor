import { test, expect } from "../../fixtures/test.js";

test.describe("Examiner classroom management", () => {
  test.use({ role: "examiner" });

  test("creates classrooms, validates expiry input, toggles open/close, and regenerates join codes", async ({
    authedPage: page,
  }) => {
    await page.goto("/examiner");
    await expect(page.getByRole("heading", { name: "Examiner console" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "E2E Seeded Lab" })).toBeVisible();

    // Blank submission validation
    await page.getByRole("button", { name: "Create classroom" }).click();
    await expect(page.locator('main [role="alert"]')).toHaveText("Enter a classroom name.");

    // Create classroom with future expiry
    const className = "E2E Dynamic Section";
    await page.getByLabel("Classroom name").fill(className);
    await page.getByLabel("Code expires at (optional)").fill("2028-06-15T14:30");
    await page.getByRole("button", { name: "Create classroom" }).click();

    await expect(page.getByRole("heading", { name: className })).toBeVisible();
    const card = page.locator("div.rounded-lg", { has: page.getByRole("heading", { name: className }) }).first();
    const initialCode = (await card.locator("span.font-mono.tracking-widest").textContent())?.trim();
    expect(initialCode).toMatch(/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/);
    await expect(card).toContainText("Expires:");

    // Close then reopen classroom
    await page.getByRole("button", { name: `Close ${className}` }).click();
    await expect(page.getByRole("button", { name: `Open ${className}` })).toBeVisible();
    await expect(card).toContainText("Closed");

    await page.getByRole("button", { name: `Open ${className}` }).click();
    await expect(page.getByRole("button", { name: `Close ${className}` })).toBeVisible();
    await expect(card).toContainText("Open");

    // Regenerate code produces a different valid 8-char code
    await page.getByRole("button", { name: `Regenerate code for ${className}` }).click();
    await expect(card.locator("span.font-mono.tracking-widest")).not.toHaveText(initialCode);
    const updatedCode = (await card.locator("span.font-mono.tracking-widest").textContent())?.trim();
    expect(updatedCode).toMatch(/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/);
  });
});
