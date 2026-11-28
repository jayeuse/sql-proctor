import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "../test/msw/server";
import StudentExamWorkspace from "../components/StudentExamWorkspace";
import StudentExamResult from "../components/StudentExamResult";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
  useParams: () => ({ attemptId: "att-1" }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

const sampleAttempt = {
  id: "att-1",
  classroom_exam_id: "ce-1",
  title: "SQL Midterm 1",
  description: "Practical SQL assessment",
  instructions: "Write MariaDB SQL.",
  duration_minutes: 60,
  passing_score_percent: "60.00",
  max_score: "15.00",
  status: "in_progress",
  grading_status: "pending",
  started_at: "2026-11-28T05:00:00Z",
  expires_at: "2026-11-28T06:00:00Z",
  remaining_seconds: 3500,
  settings: {
    allow_query_preview: true,
    show_points: true,
    immediate_auto_results: false,
    max_query_runs_per_question: 20,
  },
  schema_summary: {
    tables: [
      {
        name: "products",
        type: "BASE TABLE",
        row_count: 3,
        columns: [
          { name: "id", type: "int(11)", nullable: false, key: "PRI", default: null },
          { name: "price", type: "decimal(8,2)", nullable: false, key: "", default: null },
        ],
        foreign_keys: [],
      },
    ],
  },
  questions: [
    {
      id: "q-1",
      position: 1,
      question_type: "sql_select",
      grading_mode: "auto",
      title: "Select Expensive Items",
      prompt: "Return ids where price > 20.",
      points: "10.00",
      starter_sql: "SELECT id FROM products;",
      options: [],
      visible_test_cases: [{ id: "tc-1", name: "Standard dataset", position: 1, weight_points: "10.00" }],
      saved_answer: null,
    },
    {
      id: "q-2",
      position: 2,
      question_type: "mcq",
      grading_mode: "auto",
      title: "NULL Check",
      prompt: "Which checks for NULL?",
      points: "5.00",
      starter_sql: null,
      options: [
        { id: "opt-1", position: 1, label: "IS NULL" },
        { id: "opt-2", position: 2, label: "= NULL" },
      ],
      visible_test_cases: [],
      saved_answer: null,
    },
  ],
};

describe("StudentExamWorkspace", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
    );
  });

  it("displays sanitized SQL error output without leaking credentials or stack traces", async () => {
    const user = userEvent.setup();
    server.use(
      http.get(`${API}/student/attempts/att-1`, () => HttpResponse.json({ data: sampleAttempt })),
      http.post(`${API}/student/attempts/att-1/questions/q-1/run`, () =>
        HttpResponse.json({
          data: {
            status: "policy_blocked",
            columns: [],
            rows: [],
            row_count: 0,
            truncated: false,
            execution_ms: 1,
            error: "Access to system schema `mysql` is not permitted.",
          },
        }),
      ),
    );

    render(<StudentExamWorkspace attemptId="att-1" />);
    expect(await screen.findByText("Select Expensive Items")).toBeVisible();
    await user.click(screen.getByRole("button", { name: /Run Query/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Access to system schema `mysql` is not permitted.",
    );
  });

  it("locks editor and links to result when attempt expires (409 attempt_expired)", async () => {
    const user = userEvent.setup();
    server.use(
      http.get(`${API}/student/attempts/att-1`, () => HttpResponse.json({ data: sampleAttempt })),
      http.put(`${API}/student/attempts/att-1/answers/q-1`, () =>
        HttpResponse.json(
          {
            message: "Attempt time has expired and was automatically submitted.",
            code: "attempt_expired",
          },
          { status: 409 },
        ),
      ),
    );

    render(<StudentExamWorkspace attemptId="att-1" />);
    expect(await screen.findByText("Select Expensive Items")).toBeVisible();
    await user.click(screen.getByRole("button", { name: /Save Draft/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/expired/i);
    expect(screen.getByRole("link", { name: /View Result/i })).toHaveAttribute(
      "href",
      "/student/exams/att-1/result",
    );
  });

  it("locks previously submitted answer on load while leaving sibling questions editable", async () => {
    const attemptWithLockedQ1 = {
      ...sampleAttempt,
      questions: [
        {
          ...sampleAttempt.questions[0],
          saved_answer: {
            submitted_sql: "SELECT id FROM products WHERE price > 20;",
            submitted_at: "2026-11-28T05:10:00Z",
            is_submitted: true,
            updated_at: "2026-11-28T05:10:00Z",
          },
        },
        sampleAttempt.questions[1],
      ],
    };

    server.use(
      http.get(`${API}/student/attempts/att-1`, () => HttpResponse.json({ data: attemptWithLockedQ1 })),
    );

    const user = userEvent.setup();
    render(<StudentExamWorkspace attemptId="att-1" />);

    const sqlInput = await screen.findByLabelText("Your SQL Query");
    expect(sqlInput).toHaveValue("SELECT id FROM products WHERE price > 20;");
    expect(sqlInput).toHaveAttribute("readonly");
    expect(screen.getByText(/Answer submitted and locked/i)).toBeVisible();
    expect(screen.getByRole("button", { name: /Run Query/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Save Draft/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Reset Starter SQL/i })).toBeDisabled();
    expect(screen.queryByRole("button", { name: /^Submit Answer$/i })).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Q2/i }));
    expect(await screen.findByLabelText("IS NULL")).not.toBeDisabled();
    expect(screen.getByRole("button", { name: /Save Draft/i })).not.toBeDisabled();
    expect(screen.getByRole("button", { name: /^Submit Answer$/i })).not.toBeDisabled();
  });

  it("requires confirmation to submit an answer, preserves text, locks inputs, and shows action feedback", async () => {
    const user = userEvent.setup();
    const putPayloads = [];
    const attemptWithShort = {
      ...sampleAttempt,
      questions: [
        ...sampleAttempt.questions,
        {
          id: "q-3",
          position: 3,
          question_type: "short_answer",
          grading_mode: "manual",
          title: "Explain Normalization",
          prompt: "Explain 3NF.",
          points: "5.00",
          starter_sql: null,
          options: [],
          visible_test_cases: [],
          saved_answer: null,
        },
      ],
    };

    server.use(
      http.get(`${API}/student/attempts/att-1`, () => HttpResponse.json({ data: attemptWithShort })),
      http.post(`${API}/student/attempts/att-1/questions/q-1/run`, () =>
        HttpResponse.json({
          data: {
            status: "succeeded",
            columns: ["id", "id", "price"],
            rows: [{ id: 2, id_2: 77, price: "25.00" }],
            row_count: 1,
            truncated: false,
            execution_ms: 9,
            error: null,
          },
        }),
      ),
      http.put(`${API}/student/attempts/att-1/answers/:questionId`, async ({ params, request }) => {
        const body = await request.json();
        putPayloads.push({ questionId: params.questionId, body });
        return HttpResponse.json({
          data: {
            question_id: params.questionId,
            submitted_at: body.submit_answer ? "2026-11-28T05:12:00Z" : null,
            is_submitted: Boolean(body.submit_answer),
            updated_at: "2026-11-28T05:12:00Z",
          },
        });
      }),
    );

    render(<StudentExamWorkspace attemptId="att-1" />);
    const sqlInput = await screen.findByLabelText("Your SQL Query");

    await user.click(screen.getByRole("button", { name: /Reset Starter SQL/i }));
    expect(screen.getByText(/Starter SQL restored/i)).toBeVisible();

    await user.click(screen.getByRole("button", { name: /Run Query/i }));
    expect(await screen.findByText("25.00")).toBeVisible();
    expect(screen.getByRole("cell", { name: "77" })).toBeVisible();
    expect(screen.getByText(/Query succeeded/i)).toBeVisible();

    await user.click(screen.getByRole("button", { name: /Save Draft/i }));
    expect(await screen.findByText("Draft saved.")).toBeVisible();
    expect(putPayloads.at(-1)).toEqual({
      questionId: "q-1",
      body: { submitted_sql: "SELECT id FROM products;", submit_answer: false },
    });

    await user.clear(sqlInput);
    await user.type(sqlInput, "SELECT price FROM products;");

    // Cancel keeps answer editable and does not send submit_answer: true
    await user.click(screen.getByRole("button", { name: /^Submit Answer$/i }));
    expect(screen.getByText(/Submit your answer for Q1\?/i)).toBeVisible();
    await user.click(screen.getByRole("button", { name: /^Cancel$/i }));
    expect(screen.queryByText(/Submit your answer for Q1\?/i)).not.toBeInTheDocument();
    expect(sqlInput).not.toHaveAttribute("readonly");
    expect(putPayloads.some((item) => item.body.submit_answer === true)).toBe(false);

    // Confirm submits and locks Q1 while keeping submitted SQL visible
    await user.click(screen.getByRole("button", { name: /^Submit Answer$/i }));
    await user.click(screen.getByRole("button", { name: /Confirm Submit Answer/i }));
    expect(await screen.findByText(/Answer submitted and locked/i)).toBeVisible();
    expect(putPayloads.at(-1)).toEqual({
      questionId: "q-1",
      body: { submitted_sql: "SELECT price FROM products;", submit_answer: true },
    });
    expect(sqlInput).toHaveValue("SELECT price FROM products;");
    expect(sqlInput).toHaveAttribute("readonly");
    expect(screen.getByRole("button", { name: /Run Query/i })).toBeDisabled();
    expect(screen.getByRole("button", { name: /Save Draft/i })).toBeDisabled();

    await user.type(sqlInput, " DROP TABLE products;");
    expect(sqlInput).toHaveValue("SELECT price FROM products;");

    // Short answer submission locks textarea and preserves text
    await user.click(screen.getByRole("button", { name: /Q3/i }));
    const shortInput = await screen.findByLabelText("Your Answer");
    await user.type(shortInput, "No transitive dependencies.");
    await user.click(screen.getByRole("button", { name: /^Submit Answer$/i }));
    await user.click(screen.getByRole("button", { name: /Confirm Submit Answer/i }));
    expect(await screen.findByText(/Answer submitted and locked/i)).toBeVisible();
    expect(shortInput).toHaveValue("No transitive dependencies.");
    expect(shortInput).toBeDisabled();

    // MCQ remains editable until confirm-submitted, then keeps checked options disabled
    await user.click(screen.getByRole("button", { name: /Q2/i }));
    const optionBox = await screen.findByLabelText("IS NULL");
    expect(optionBox).not.toBeDisabled();
    await user.click(optionBox);
    await user.click(screen.getByRole("button", { name: /^Submit Answer$/i }));
    await user.click(screen.getByRole("button", { name: /Confirm Submit Answer/i }));
    expect(await screen.findByText(/Answer submitted and locked/i)).toBeVisible();
    expect(optionBox).toBeChecked();
    expect(optionBox).toBeDisabled();
  });

  it("renders schema explorer, runs tabular SQL query, saves MCQ, and submits exam", async () => {
    const user = userEvent.setup();
    let submitted = false;
    server.use(
      http.get(`${API}/student/attempts/att-1`, () => HttpResponse.json({ data: sampleAttempt })),
      http.post(`${API}/student/attempts/att-1/questions/q-1/run`, () =>
        HttpResponse.json({
          data: {
            status: "succeeded",
            columns: ["id", "id", "price"],
            rows: [{ id: 2, id_2: 77, price: "25.00" }],
            row_count: 1,
            truncated: false,
            execution_ms: 9,
            error: null,
          },
        }),
      ),
      http.put(`${API}/student/attempts/att-1/answers/q-1`, () =>
        HttpResponse.json({ data: { question_id: "q-1", is_submitted: false, updated_at: "2026-11-28T05:10:00Z" } }),
      ),
      http.put(`${API}/student/attempts/att-1/answers/q-2`, () =>
        HttpResponse.json({ data: { question_id: "q-2", is_submitted: false, updated_at: "2026-11-28T05:11:00Z" } }),
      ),
      http.post(`${API}/student/attempts/att-1/submit`, () => {
        submitted = true;
        return HttpResponse.json({
          data: { ...sampleAttempt, status: "submitted", grading_status: "auto_graded" },
        });
      }),
    );

    render(<StudentExamWorkspace attemptId="att-1" />);
    expect(await screen.findByText("products")).toBeVisible();
    expect(screen.getByText(/decimal\(8,2\)/i)).toBeVisible();

    await user.click(screen.getByRole("button", { name: /Run Query/i }));
    expect(await screen.findByText("25.00")).toBeVisible();

    await user.click(screen.getByRole("button", { name: /Q2/i }));
    await user.click(await screen.findByLabelText("IS NULL"));
    await user.click(screen.getByRole("button", { name: /Save Draft/i }));
    expect(await screen.findByText("Draft saved.")).toBeVisible();

    await user.click(screen.getByRole("button", { name: /^Submit Exam$/i }));
    await user.click(screen.getByRole("button", { name: /^Confirm Submit$/i }));

    await waitFor(() => expect(submitted).toBe(true));
    expect(pushMock).toHaveBeenCalledWith("/student/exams/att-1/result");
  });

  it("flushes dirty answers across fast question switching and before Submit Exam without dropping edits", async () => {
    const user = userEvent.setup();
    const putOrder = [];
    let submitCalled = false;

    server.use(
      http.get(`${API}/student/attempts/att-1`, () => HttpResponse.json({ data: sampleAttempt })),
      http.put(`${API}/student/attempts/att-1/answers/:qId`, async ({ params, request }) => {
        const body = await request.json();
        putOrder.push({ qId: params.qId, body });
        return HttpResponse.json({
          data: { question_id: params.qId, is_submitted: false, updated_at: "2026-11-28T05:12:00Z" },
        });
      }),
      http.post(`${API}/student/attempts/att-1/submit`, () => {
        submitCalled = true;
        return HttpResponse.json({
          data: { ...sampleAttempt, status: "submitted", grading_status: "auto_graded" },
        });
      }),
    );

    render(<StudentExamWorkspace attemptId="att-1" />);
    const sqlInput = await screen.findByLabelText("Your SQL Query");
    await user.clear(sqlInput);
    await user.type(sqlInput, "SELECT price FROM products;");

    // Immediately switch to Q2 before the 800ms debounce timer fires
    await user.click(screen.getByRole("button", { name: /Q2/i }));
    await user.click(await screen.findByLabelText("IS NULL"));

    // Submit immediately before Q2 debounce fires
    await user.click(screen.getByRole("button", { name: /^Submit Exam$/i }));
    await user.click(screen.getByRole("button", { name: /^Confirm Submit$/i }));

    await waitFor(() => expect(submitCalled).toBe(true));
    expect(putOrder.some((entry) => entry.qId === "q-1" && entry.body.submitted_sql === "SELECT price FROM products;")).toBe(true);
    expect(putOrder.some((entry) => entry.qId === "q-2" && entry.body.selected_option_ids?.[0] === "opt-1")).toBe(true);
  });

  it("aborts exam submission if flushing a dirty answer fails", async () => {
    const user = userEvent.setup();
    let submitCalled = false;

    server.use(
      http.get(`${API}/student/attempts/att-1`, () => HttpResponse.json({ data: sampleAttempt })),
      http.put(`${API}/student/attempts/att-1/answers/q-1`, () =>
        HttpResponse.json({ message: "Database temporarily unavailable." }, { status: 500 }),
      ),
      http.post(`${API}/student/attempts/att-1/submit`, () => {
        submitCalled = true;
        return HttpResponse.json({ data: sampleAttempt });
      }),
    );

    render(<StudentExamWorkspace attemptId="att-1" />);
    const sqlInput = await screen.findByLabelText("Your SQL Query");
    await user.type(sqlInput, " ");

    await user.click(screen.getByRole("button", { name: /^Submit Exam$/i }));
    await user.click(screen.getByRole("button", { name: /^Confirm Submit$/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/unavailable/i);
    expect(submitCalled).toBe(false);
    expect(pushMock).not.toHaveBeenCalled();
  });
});

describe("StudentExamResult", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
    );
  });

  it("hides scores and shows pending release message when results are not released", async () => {
    server.use(
      http.get(`${API}/student/attempts/att-1/result`, () =>
        HttpResponse.json({
          data: {
            id: "att-1",
            title: "SQL Midterm 1",
            status: "submitted",
            grading_status: "needs_review",
            submitted_at: "2026-11-28T05:30:00Z",
            released: false,
            message: "Submitted — awaiting examiner result release.",
          },
        }),
      ),
    );

    render(<StudentExamResult attemptId="att-1" />);
    expect(await screen.findByText(/awaiting examiner result release/i)).toBeVisible();
    expect(screen.queryByText(/Score:/i)).not.toBeInTheDocument();
  });

  it("renders released scores, visible tests, hidden summary count, and examiner feedback", async () => {
    server.use(
      http.get(`${API}/student/attempts/att-1/result`, () =>
        HttpResponse.json({
          data: {
            id: "att-1",
            title: "SQL Midterm 1",
            status: "submitted",
            grading_status: "finalized",
            released: true,
            final_score: "15.00",
            max_score: "15.00",
            percentage: "100.00",
            passing_score_percent: "60.00",
            passed: true,
            questions: [
              {
                question_id: "q-1",
                position: 1,
                title: "Select Expensive Items",
                question_type: "sql_select",
                max_points: "15.00",
                final_score: "15.00",
                grading_status: "auto_graded",
                submitted_sql: "SELECT id FROM products WHERE price > 20;",
                examiner_feedback: "Clean query.",
                visible_test_results: [
                  {
                    name: "Standard dataset",
                    passed: true,
                    points_earned: "10.00",
                    weight_points: "10.00",
                    message: "Matched reference",
                  },
                ],
                hidden_tests_summary: { total: 1, passed: 1 },
              },
            ],
          },
        }),
      ),
    );

    render(<StudentExamResult attemptId="att-1" />);
    expect(await screen.findByText(/15\.00 \/ 15\.00/)).toBeVisible();
    expect(screen.getByText("Passed")).toBeVisible();
    expect(screen.getByText("Full Credit")).toBeVisible();
    expect(screen.getByText(/Hidden tests: 1 \/ 1 passed/i)).toBeVisible();
    expect(screen.getByText("Clean query.")).toBeVisible();
  });

  it("renders fail, partial credit, pending manual review cues, and selected MCQ labels", async () => {
    server.use(
      http.get(`${API}/student/attempts/att-1/result`, () =>
        HttpResponse.json({
          data: {
            id: "att-1",
            title: "SQL Midterm 2",
            status: "submitted",
            grading_status: "needs_review",
            released: true,
            final_score: "15.00",
            max_score: "35.00",
            percentage: "42.86",
            passing_score_percent: "60.00",
            passed: null,
            questions: [
              {
                question_id: "q-1",
                position: 1,
                title: "Top Customers",
                question_type: "sql_select",
                max_points: "10.00",
                final_score: "0.00",
                grading_status: "auto_graded",
                submitted_sql: "SELECT * FROM customers;",
                visible_test_results: [
                  {
                    name: "Sorted top 3",
                    passed: false,
                    points_earned: "0.00",
                    weight_points: "6.00",
                    message: "Row count mismatch",
                  },
                ],
                hidden_tests_summary: { total: 1, passed: 0 },
              },
              {
                question_id: "q-2",
                position: 2,
                title: "Update Discount",
                question_type: "sql_dml",
                max_points: "10.00",
                final_score: "5.00",
                grading_status: "auto_graded",
                submitted_sql: "UPDATE items SET price = price * 0.9;",
                visible_test_results: [
                  {
                    name: "Verify updated table",
                    passed: true,
                    points_earned: "5.00",
                    weight_points: "5.00",
                  },
                ],
                hidden_tests_summary: { total: 1, passed: 0 },
              },
              {
                question_id: "q-3",
                position: 3,
                title: "Explain Isolation",
                question_type: "short_answer",
                max_points: "5.00",
                final_score: "0.00",
                grading_status: "needs_review",
                submitted_text: "Uses per-run schemas.",
                visible_test_results: [],
                hidden_tests_summary: { total: 0, passed: 0 },
              },
              {
                question_id: "q-4",
                position: 4,
                title: "NULL Semantics",
                question_type: "mcq",
                max_points: "10.00",
                final_score: "10.00",
                grading_status: "auto_graded",
                selected_options: [{ id: "opt-1", label: "IS NULL" }],
                visible_test_results: [],
                hidden_tests_summary: { total: 0, passed: 0 },
              },
            ],
          },
        }),
      ),
    );

    render(<StudentExamResult attemptId="att-1" />);
    expect(await screen.findByText("Pending Manual Review")).toBeVisible();
    expect(screen.getByText("Failed")).toBeVisible();
    expect(screen.getByText("Partial Credit")).toBeVisible();
    expect(screen.getByText("Needs Review")).toBeVisible();
    expect(screen.getByText("Full Credit")).toBeVisible();
    expect(screen.getByText("Row count mismatch")).toBeVisible();
    expect(screen.getByText("IS NULL")).toBeVisible();
    expect(screen.getAllByText(/Hidden tests: 0 \/ 1 passed/i)).toHaveLength(2);
  });

  it("renders Not Passed badge when final score falls below passing threshold", async () => {
    server.use(
      http.get(`${API}/student/attempts/att-1/result`, () =>
        HttpResponse.json({
          data: {
            id: "att-1",
            title: "SQL Midterm 3",
            status: "submitted",
            grading_status: "auto_graded",
            released: true,
            final_score: "30.00",
            max_score: "60.00",
            percentage: "50.00",
            passing_score_percent: "60.00",
            passed: false,
            questions: [],
          },
        }),
      ),
    );

    render(<StudentExamResult attemptId="att-1" />);
    expect(await screen.findByText("Not Passed")).toBeVisible();
    expect(screen.getByText(/Passing threshold: 60.00%/i)).toBeVisible();
  });
});
