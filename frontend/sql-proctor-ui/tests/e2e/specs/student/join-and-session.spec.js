import { test, expect } from "../../fixtures/test.js";
import { clearTestRateLimits } from "../../helpers/backend.mjs";

test.describe("Student Kahoot entry, cookies, conflicts, closed class exit, and lockout", () => {
  test.beforeEach(() => {
    clearTestRateLimits();
  });

  test("handles join validation, httpOnly cookie isolation, duplicate 409, superseded join cleanup, and closed-class leave", async ({
    browser,
    authedPage: examinerPage,
  }) => {
    // Prepare two open classrooms as Examiner
    await examinerPage.goto("/examiner");

    await examinerPage.getByLabel("Classroom name").fill("E2E Student Class A");
    await examinerPage.getByRole("button", { name: "Create classroom" }).click();
    await expect(examinerPage.getByRole("heading", { name: "E2E Student Class A" })).toBeVisible();
    const cardA = examinerPage
      .locator("div.rounded-lg", { has: examinerPage.getByRole("heading", { name: "E2E Student Class A" }) })
      .first();
    const codeA = (await cardA.locator("span.font-mono.tracking-widest").textContent())?.trim();

    await examinerPage.getByLabel("Classroom name").fill("E2E Student Class B");
    await examinerPage.getByRole("button", { name: "Create classroom" }).click();
    await expect(examinerPage.getByRole("heading", { name: "E2E Student Class B" })).toBeVisible();
    const cardB = examinerPage
      .locator("div.rounded-lg", { has: examinerPage.getByRole("heading", { name: "E2E Student Class B" }) })
      .first();
    const codeB = (await cardB.locator("span.font-mono.tracking-widest").textContent())?.trim();

    // Student 1 context
    const student1Context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const student1Page = await student1Context.newPage();

    // Unauthenticated student visiting /student redirects to /
    await student1Page.goto("/student");
    await student1Page.waitForURL("**/");

    // Empty submit & unknown class code 404
    await student1Page.getByRole("button", { name: "Join class" }).click();
    await expect(student1Page.locator('main [role="alert"]')).toHaveText("Enter your display name and class code.");

    await student1Page.getByLabel("Display name").fill("Ada Lovelace");
    await student1Page.getByLabel("Class code").fill("ZZZZ9999");
    await student1Page.getByRole("button", { name: "Join class" }).click();
    await expect(student1Page.locator('main [role="alert"]')).toHaveText("Class code not found.");

    // Pasting padded lowercase code normalizes cleanly without clipping final character
    await student1Page.getByLabel("Class code").fill(`  ${codeA.toLowerCase()}  `);
    await expect(student1Page.getByLabel("Class code")).toHaveValue(codeA);
    await student1Page.getByRole("button", { name: "Join class" }).click();
    await student1Page.waitForURL("**/student");

    await expect(student1Page.getByText("Ada Lovelace")).toBeVisible();
    await expect(student1Page.getByText("E2E Student Class A")).toBeVisible();
    await expect(student1Page.getByText(codeA)).toBeVisible();

    // Verify encrypted HTTP-only SameSite=Lax cookie is invisible to JS
    const studentCookies = await student1Context.cookies();
    const studentSessionCookie = studentCookies.find((c) => c.name === "student_session_id");
    expect(studentSessionCookie).toBeTruthy();
    expect(studentSessionCookie.httpOnly).toBe(true);
    expect(studentSessionCookie.sameSite).toBe("Lax");

    const jsVisibleCookies = await student1Page.evaluate(() => document.cookie);
    expect(jsVisibleCookies).not.toContain("student_session_id");

    // Student session cannot access /admin or /examiner
    await student1Page.goto("/admin");
    await student1Page.waitForURL("**/login");

    // Student 2 tries duplicate display name (case + whitespace variant) -> 409 Conflict
    const student2Context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const student2Page = await student2Context.newPage();
    await student2Page.goto("/join");
    await student2Page.getByLabel("Display name").fill("  ada   lovelace  ");
    await student2Page.getByLabel("Class code").fill(codeA);
    await student2Page.getByRole("button", { name: "Join class" }).click();
    await expect(student2Page.locator('main [role="alert"]')).toHaveText("That display name is already in use in this class.");

    // Student 1 switches directly from Class A to Class B -> revokes Class A session cleanly
    await student1Page.goto("/");
    await student1Page.getByLabel("Display name").fill("Ada Lovelace");
    await student1Page.getByLabel("Class code").fill(codeB);
    await student1Page.getByRole("button", { name: "Join class" }).click();
    await student1Page.waitForURL("**/student");
    await expect(student1Page.getByText("E2E Student Class B")).toBeVisible();

    // Examiner verifies Class A count dropped to 0 and Class B is 1
    await examinerPage.reload();
    await expect(cardA).toContainText("Active students: 0");
    await expect(cardB).toContainText("Active students: 1");

    // Examiner closes Class B; Student 1 sees class_closed AND can still click Leave Class cleanly
    await examinerPage.getByRole("button", { name: "Close E2E Student Class B" }).click();
    await expect(cardB).toContainText("Closed");

    await student1Page.reload();
    await expect(student1Page.locator('main [role="alert"]')).toHaveText("This class is currently closed.");
    await student1Page.getByRole("button", { name: "Leave Class" }).click();
    await student1Page.waitForURL("**/");

    await student1Context.close();
    await student2Context.close();
  });

  test("enforces student join rate limit (429) on excessive invalid attempts", async ({ page }) => {
    await page.goto("/");

    for (let i = 0; i < 10; i += 1) {
      await page.getByLabel("Display name").fill(`Rate Student ${i}`);
      await page.getByLabel("Class code").fill("NONE8888");
      await page.getByRole("button", { name: "Join class" }).click();
      await expect(page.locator('main [role="alert"]')).toHaveText("Class code not found.");
    }

    await page.getByLabel("Display name").fill("Rate Student 11");
    await page.getByLabel("Class code").fill("NONE8888");
    await page.getByRole("button", { name: "Join class" }).click();
    await expect(page.locator('main [role="alert"]')).toContainText("Too many join attempts.");
  });
});
