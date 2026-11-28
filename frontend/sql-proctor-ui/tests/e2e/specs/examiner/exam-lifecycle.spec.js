import path from "node:path";
import { test, expect } from "../../fixtures/test.js";

const AUTH_DIR = path.resolve(import.meta.dirname, "../../.auth");

test.describe("End-to-end Exam Authoring, MariaDB Execution, Examination, Grading, and Admin Oversight", () => {
  test.use({ role: "examiner" });

  test("executes complete lifecycle across Examiner, Student, and Admin roles", async ({
    authedPage: examinerPage,
    browser,
  }) => {
    test.setTimeout(90_000);

    // 1. Create and validate MariaDB dataset
    await examinerPage.goto("/examiner/datasets");
    await expect(examinerPage.getByRole("heading", { name: "SQL Datasets" })).toBeVisible();

    await examinerPage.getByLabel("Dataset Name").fill("E2E Store");
    await examinerPage.getByLabel("Description").fill("Catalog dataset for E2E verification");

    await expect(examinerPage.locator(".monaco-editor").first()).toBeVisible({ timeout: 15_000 });
    const plainButtons = examinerPage.getByRole("button", { name: /Plain text/i });
    await plainButtons.first().click();
    await plainButtons.first().click();

    await examinerPage
      .getByLabel("Schema DDL")
      .fill("CREATE TABLE e2e_items (id INT PRIMARY KEY, title VARCHAR(80) NOT NULL, price DECIMAL(8,2) NOT NULL);");
    await examinerPage
      .getByLabel("Seed SQL")
      .fill("INSERT INTO e2e_items VALUES (1, 'SQL Guide', 15.00), (2, 'Index Tuning', 45.00);");

    await examinerPage.getByRole("button", { name: "Create Dataset" }).click();
    await expect(examinerPage.getByRole("status")).toContainText("Dataset created");

    await examinerPage.getByRole("button", { name: "Validate on MariaDB" }).click();
    await expect(examinerPage.getByRole("status")).toContainText("validated cleanly on MariaDB");
    await expect(examinerPage.getByText("e2e_items", { exact: true })).toBeVisible();

    // 2. Create a dedicated open classroom so this E2E run is isolated
    await examinerPage.goto("/examiner");
    const classroomName = "E2E Exam Classroom";
    await examinerPage.getByLabel("Classroom name").fill(classroomName);
    await examinerPage.getByRole("button", { name: "Create classroom" }).click();
    await expect(examinerPage.getByRole("heading", { name: classroomName })).toBeVisible();
    const classCard = examinerPage.locator("div.rounded-lg", {
      has: examinerPage.getByRole("heading", { name: classroomName }),
    }).first();
    const joinCode = (await classCard.locator("span.font-mono.tracking-widest").textContent())?.trim();
    expect(joinCode).toMatch(/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{8}$/);

    // 3. Create Exam and add SQL, MCQ, and Short Answer questions
    await examinerPage.goto("/examiner/exams");
    await examinerPage.getByRole("button", { name: "New Exam" }).click();
    await examinerPage.getByLabel("Exam Title").fill("E2E Practical Exam");
    await examinerPage.getByLabel("SQL Dataset").selectOption({ label: "E2E Store (Validated)" });
    await examinerPage.getByLabel("Duration (minutes)").fill("45");
    await examinerPage.getByLabel("Passing Score (%)").fill("60");
    await examinerPage.getByRole("button", { name: "Create Exam" }).click();

    await examinerPage.waitForURL("**/examiner/exams/**");
    const examUrl = examinerPage.url();
    await expect(examinerPage.getByRole("heading", { name: "E2E Practical Exam" })).toBeVisible();

    // Add Q1: sql_select (auto, 10 pts)
    await examinerPage.getByRole("tab", { name: /Questions/i }).click();
    await examinerPage.getByLabel("Question Title").fill("Expensive Books");
    await examinerPage.getByLabel("Prompt").fill("Return id and title of items where price > 20.");
    await examinerPage.getByLabel("Points").fill("10");

    const qSqlToggles = examinerPage.getByRole("button", { name: /Plain text/i });
    await qSqlToggles.first().click();
    await qSqlToggles.first().click();
    await examinerPage.getByLabel("Starter SQL").fill("SELECT id, title FROM e2e_items;");
    await examinerPage
      .getByLabel("Reference SQL (Staff Only)")
      .fill("SELECT id, title FROM e2e_items WHERE price > 20;");
    await examinerPage.getByRole("button", { name: "Save Question" }).click();
    await expect(examinerPage.getByText("Expensive Books")).toBeVisible();

    // Add Q2: mcq (auto, 5 pts)
    await examinerPage.getByRole("button", { name: "New Question" }).click();
    await examinerPage.getByLabel("Question Type").selectOption("mcq");
    await examinerPage.getByLabel("Question Title").fill("Primary Key Property");
    await examinerPage.getByLabel("Prompt").fill("What guarantees uniqueness and non-null values?");
    await examinerPage.getByLabel("Points").fill("5");
    await examinerPage.getByLabel("Option 1 Label").fill("PRIMARY KEY");
    await examinerPage.getByLabel("Option 2 Label").fill("TEXT INDEX");
    await examinerPage.getByRole("button", { name: "Save Question" }).click();
    await expect(examinerPage.getByText("Primary Key Property")).toBeVisible();

    // Add Q3: short_answer (manual, 5 pts)
    await examinerPage.getByRole("button", { name: "New Question" }).click();
    await examinerPage.getByLabel("Question Type").selectOption("short_answer");
    await examinerPage.getByLabel("Question Title").fill("Indexing Benefit");
    await examinerPage.getByLabel("Prompt").fill("Why create an index on price?");
    await examinerPage.getByLabel("Points").fill("5");
    await examinerPage.getByRole("button", { name: "Save Question" }).click();
    await expect(examinerPage.getByText("Indexing Benefit")).toBeVisible();

    // 4. Validate, Publish, and Assign to Classroom
    await examinerPage.getByRole("tab", { name: "Validate & Publish" }).click();
    await examinerPage.getByRole("button", { name: "Run Validation" }).click();
    await expect(examinerPage.getByRole("status")).toContainText("ready to publish");

    await examinerPage.getByRole("button", { name: "Publish Exam" }).click();
    await expect(examinerPage.getByText("v1", { exact: true })).toBeVisible();
    await expect(examinerPage.getByText(/locked/i)).toBeVisible();

    await examinerPage.getByRole("tab", { name: /Classrooms/i }).click();
    await examinerPage.getByLabel("Classroom").selectOption({ label: `${classroomName} (${joinCode})` });
    await examinerPage.getByRole("button", { name: "Assign to Classroom" }).click();
    await expect(examinerPage.locator("p", { hasText: classroomName })).toBeVisible();

    // 5. Student Context: join classroom, take exam, execute MariaDB queries, submit
    const studentContext = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const studentPage = await studentContext.newPage();
    try {
      await studentPage.goto("/");
      await studentPage.getByLabel("Class code").fill(joinCode);
      await studentPage.getByLabel("Display name").fill("Grace Hopper");
      await studentPage.getByRole("button", { name: "Join Class" }).click();
      await studentPage.waitForURL("**/student");

      await expect(studentPage.getByText("E2E Practical Exam")).toBeVisible();
      await studentPage.getByRole("button", { name: "Start Exam" }).click();
      await studentPage.waitForURL("**/student/exams/**");
      const attemptUrl = studentPage.url();

      const schemaSection = studentPage.locator("section", {
        has: studentPage.getByRole("heading", { name: "Database Schema" }),
      });
      await expect(schemaSection.getByText("e2e_items", { exact: true })).toBeVisible();
      await studentPage.getByRole("button", { name: /Plain text/i }).click();

      // Test sanitized SQL error
      await studentPage.getByLabel("Your SQL Query").fill("SELECT * FROM missing_tbl;");
      await studentPage.getByRole("button", { name: "Run Query" }).click();
      await expect(studentPage.locator('main [role="alert"]')).toContainText(/missing_tbl/i);

      // Run valid SQL solution, save draft, confirm-submit & verify persisted lock across reload
      await studentPage.getByLabel("Your SQL Query").fill("SELECT id, title FROM e2e_items WHERE price > 20;");
      await studentPage.getByRole("button", { name: "Run Query" }).click();
      await expect(studentPage.getByRole("cell", { name: "Index Tuning" })).toBeVisible();
      await expect(studentPage.getByText(/Query succeeded/i)).toBeVisible();

      await studentPage.getByRole("button", { name: "Save Draft" }).click();
      await expect(studentPage.getByText("Draft saved.")).toBeVisible();

      await studentPage.getByRole("button", { name: "Submit Answer" }).click();
      await expect(studentPage.getByText(/Submit your answer for Q1\?/i)).toBeVisible();
      await studentPage.getByRole("button", { name: "Cancel" }).click();
      await expect(studentPage.getByLabel("Your SQL Query")).not.toHaveAttribute("readonly", "");

      await studentPage.getByRole("button", { name: "Submit Answer" }).click();
      await studentPage.getByRole("button", { name: "Confirm Submit Answer" }).click();
      await expect(studentPage.getByText(/Answer submitted and locked/i)).toBeVisible();
      await expect(studentPage.getByRole("button", { name: "Run Query" })).toBeDisabled();

      // Reload verifies Q1 answer & lock persist from PostgreSQL
      await studentPage.reload();
      await expect(studentPage.getByText(/Answer submitted and locked/i)).toBeVisible();
      await studentPage.getByRole("button", { name: /Plain text/i }).click();
      await expect(studentPage.getByLabel("Your SQL Query")).toHaveValue(
        "SELECT id, title FROM e2e_items WHERE price > 20;",
      );
      await expect(studentPage.getByLabel("Your SQL Query")).toHaveAttribute("readonly", "");

      // Answer Q2 MCQ and confirm-submit
      await studentPage.getByRole("button", { name: "Q2" }).click();
      await studentPage.getByLabel("PRIMARY KEY").check();
      await studentPage.getByRole("button", { name: "Submit Answer" }).click();
      await studentPage.getByRole("button", { name: "Confirm Submit Answer" }).click();
      await expect(studentPage.getByText(/Answer submitted and locked/i)).toBeVisible();
      await expect(studentPage.getByLabel("PRIMARY KEY")).toBeChecked();
      await expect(studentPage.getByLabel("PRIMARY KEY")).toBeDisabled();

      // Answer Q3 Short Answer via Save Draft
      await studentPage.getByRole("button", { name: "Q3" }).click();
      await studentPage.getByLabel("Your Answer").fill("Speeds up range filtering on price.");
      await studentPage.getByRole("button", { name: "Save Draft" }).click();
      await expect(studentPage.getByText("Draft saved.")).toBeVisible();

      // Submit exam & verify results hidden prior to release
      await studentPage.getByRole("button", { name: "Submit Exam" }).click();
      await studentPage.getByRole("button", { name: "Confirm Submit" }).click();
      await studentPage.waitForURL("**/result");
      await expect(studentPage.getByText(/awaiting examiner result release/i)).toBeVisible();

      // 6. Examiner Grades Q3, Finalizes, and Releases Results
      await examinerPage.goto(`${examUrl}/attempts`);
      await expect(examinerPage.getByText("Grace Hopper")).toBeVisible();
      await examinerPage.getByRole("link", { name: "Grade / Review" }).click();
      await examinerPage.waitForURL("**/examiner/attempts/**");

      const scoreInputs = examinerPage.getByLabel(/Manual Score/i);
      await scoreInputs.nth(2).fill("5");
      await examinerPage.getByLabel(/Examiner Feedback/i).nth(2).fill("Spot on explanation.");
      await examinerPage.getByRole("button", { name: "Save Grade" }).nth(2).click();
      await expect(examinerPage.getByRole("status")).toContainText("Saved grade");

      await examinerPage.getByRole("button", { name: "Finalize Attempt" }).click();
      await expect(examinerPage.getByText(/20\.00 \/ 20\.00/)).toBeVisible();
      await expect(examinerPage.getByText("Passed")).toBeVisible();

      await examinerPage.goto(examUrl);
      await examinerPage.getByRole("tab", { name: /Classrooms/i }).click();
      await examinerPage.getByRole("button", { name: "Release Results" }).click();
      await expect(examinerPage.getByText("Results Released", { exact: true })).toBeVisible();

      // Student sees finalized score, visual badges, selected option labels, and examiner feedback
      await studentPage.goto(`${attemptUrl}/result`);
      await expect(studentPage.getByText(/20\.00 \/ 20\.00/)).toBeVisible();
      await expect(studentPage.getByText("Passed", { exact: true })).toBeVisible();
      await expect(studentPage.getByText("Full Credit")).toHaveCount(3);
      await expect(studentPage.getByText("PRIMARY KEY", { exact: true })).toBeVisible();
      await expect(studentPage.getByText(/Spot on explanation\./)).toBeVisible();
    } finally {
      await studentContext.close();
    }

    // 7. Admin Context: inspect Exams oversight tab and force-unpublish
    const adminContext = await browser.newContext({
      viewport: { width: 1280, height: 800 },
      storageState: path.join(AUTH_DIR, "admin.json"),
    });
    const adminPage = await adminContext.newPage();
    try {
      await adminPage.goto("/admin");
      await adminPage.getByRole("tab", { name: "Exams" }).click();
      await expect(adminPage.getByText("E2E Practical Exam")).toBeVisible();

      const row = adminPage.locator("tr", { hasText: "E2E Practical Exam" }).first();
      await row.getByRole("button", { name: "Force Unpublish" }).click();
      await adminPage.getByLabel("Reason").fill("E2E security oversight verification");
      await adminPage.getByRole("button", { name: "Confirm Force Unpublish" }).click();
      await expect(row).toContainText(/unpublished/i);

      await adminPage.getByRole("tab", { name: "Audit Logs" }).click();
      await expect(adminPage.getByText("exam.force_unpublished", { exact: true }).first()).toBeVisible();
    } finally {
      await adminContext.close();
    }
  });
});
