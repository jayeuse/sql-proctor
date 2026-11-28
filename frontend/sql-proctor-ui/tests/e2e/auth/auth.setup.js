import { test as setup, expect } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
import { bootstrapTestAdmin, resetTestDatabase } from "../helpers/backend.mjs";

const AUTH_DIR = path.resolve(import.meta.dirname, "../.auth");

setup("reset test DB, bootstrap admin, and save staff storage states", async ({ browser }) => {
  fs.mkdirSync(AUTH_DIR, { recursive: true });

  resetTestDatabase();
  const { username, password } = bootstrapTestAdmin();

  // Separate context for Admin so Admin's DB session stays alive when Examiner tests run.
  const adminContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const adminPage = await adminContext.newPage();

  await adminPage.goto("/login");
  await adminPage.getByLabel("Username").fill(username);
  await adminPage.getByLabel("Password").fill(password);
  await adminPage.getByRole("button", { name: "Sign in" }).click();
  await adminPage.waitForURL("**/admin");
  await expect(adminPage.getByRole("heading", { name: "Administration console" })).toBeVisible();

  await adminContext.storageState({ path: path.join(AUTH_DIR, "admin.json") });

  // Create seeded E2E examiners via the live Admin UI for login/examiner/student specs.
  await adminPage.getByLabel("Examiner username").fill("e2e_examiner");
  await adminPage.getByLabel("Display name (optional)").fill("Prof. E2E Examiner");
  await adminPage.getByLabel("Password (optional, min 12 chars)").fill("E2eExaminerPass123!");
  await adminPage.getByRole("button", { name: "Create examiner" }).click();
  await expect(adminPage.getByRole("cell", { name: "e2e_examiner", exact: true })).toBeVisible();

  // Create an inactive examiner for login failure QA.
  await adminPage.getByLabel("Examiner username").fill("e2e_inactive");
  await adminPage.getByLabel("Password (optional, min 12 chars)").fill("InactivePass123!");
  await adminPage.getByRole("button", { name: "Create examiner" }).click();
  await expect(adminPage.getByRole("cell", { name: "e2e_inactive", exact: true })).toBeVisible();
  await adminPage.getByRole("button", { name: "Deactivate e2e_inactive", exact: true }).click();
  await expect(adminPage.getByRole("button", { name: "Activate e2e_inactive", exact: true })).toBeVisible();

  await adminContext.close();

  // Separate context for Examiner.
  const examinerContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const examinerPage = await examinerContext.newPage();

  await examinerPage.goto("/login");
  await examinerPage.getByLabel("Username").fill("e2e_examiner");
  await examinerPage.getByLabel("Password").fill("E2eExaminerPass123!");
  await examinerPage.getByRole("button", { name: "Sign in" }).click();
  await examinerPage.waitForURL("**/examiner");
  await expect(examinerPage.getByRole("heading", { name: "Examiner console" })).toBeVisible();

  await examinerPage.getByLabel("Classroom name").fill("E2E Seeded Lab");
  await examinerPage.getByRole("button", { name: "Create classroom" }).click();
  await expect(examinerPage.getByRole("heading", { name: "E2E Seeded Lab" })).toBeVisible();

  await examinerContext.storageState({ path: path.join(AUTH_DIR, "examiner.json") });
  await examinerContext.close();
});
