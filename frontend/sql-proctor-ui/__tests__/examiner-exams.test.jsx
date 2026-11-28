import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "../test/msw/server";
import ExamsManager from "../components/ExamsManager";
import ExamEditor from "../components/ExamEditor";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
  usePathname: () => "/examiner/exams",
  useParams: () => ({ examId: "exam-1" }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

const baseExam = {
  id: "exam-1",
  examiner_id: 2,
  dataset_id: "ds-1",
  dataset: {
    id: "ds-1",
    name: "University Store",
    validated_at: "2026-11-28T05:00:00Z",
    schema_summary: { tables: [] },
  },
  title: "SQL Midterm 1",
  description: "Querying products",
  instructions: "Write clean ANSI SQL.",
  status: "draft",
  duration_minutes: 60,
  passing_score_percent: "60.00",
  total_points: "10.00",
  settings: {
    allow_query_preview: true,
    shuffle_questions: false,
    show_points: true,
    immediate_auto_results: false,
    max_query_runs_per_question: 20,
  },
  current_version_number: 0,
  is_editable: true,
  questions_count: 1,
  attempts_count: 0,
  questions: [
    {
      id: "q-1",
      exam_id: "exam-1",
      position: 1,
      question_type: "sql_select",
      grading_mode: "auto",
      title: "High Value Products",
      prompt: "Select products costing over 20.",
      points: "10.00",
      starter_sql: "SELECT * FROM products;",
      reference_sql: "SELECT id FROM products WHERE price > 20;",
      comparison_config: {},
      rubric: [],
      test_cases: [
        {
          id: "tc-1",
          name: "Base check",
          position: 1,
          weight_points: "10.00",
          is_hidden: false,
          expected_behavior: "match_reference",
        },
      ],
      options: [],
    },
  ],
  classroom_exams: [],
};

describe("ExamsManager", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "examiner1", role: "examiner" } }),
      ),
    );
  });

  it("shows API error alert when creating an exam without title fails validation (422)", async () => {
    const user = userEvent.setup();
    server.use(
      http.get(`${API}/examiner/exams`, () =>
        HttpResponse.json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
      ),
      http.get(`${API}/examiner/datasets`, () =>
        HttpResponse.json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } }),
      ),
      http.post(`${API}/examiner/exams`, () =>
        HttpResponse.json(
          { message: "The title field is required.", errors: { title: ["The title field is required."] } },
          { status: 422 },
        ),
      ),
    );

    render(<ExamsManager />);
    await user.click(await screen.findByRole("button", { name: /New Exam/i }));
    await user.click(screen.getByRole("button", { name: /Create Exam/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/title/i);
  });

  it("lists exams and supports cloning into a draft", async () => {
    const user = userEvent.setup();
    let cloned = false;
    server.use(
      http.get(`${API}/examiner/exams`, () =>
        HttpResponse.json({
          data: [{ ...baseExam, status: "published", is_editable: false }],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        }),
      ),
      http.get(`${API}/examiner/datasets`, () =>
        HttpResponse.json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } }),
      ),
      http.post(`${API}/examiner/exams/exam-1/clone`, () => {
        cloned = true;
        return HttpResponse.json({ data: { ...baseExam, id: "exam-copy", title: "SQL Midterm 1 (Copy)" } }, { status: 201 });
      }),
    );

    render(<ExamsManager />);
    expect(await screen.findByText("SQL Midterm 1")).toBeVisible();
    await user.click(screen.getByRole("button", { name: /Clone/i }));

    await waitFor(() => expect(cloned).toBe(true));
    expect(pushMock).toHaveBeenCalledWith("/examiner/exams/exam-copy");
  });
});

describe("ExamEditor", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "examiner1", role: "examiner" } }),
      ),
      http.get(`${API}/examiner/datasets`, () =>
        HttpResponse.json({
          data: [baseExam.dataset],
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
        }),
      ),
      http.get(`${API}/examiner/classes`, () =>
        HttpResponse.json({
          data: [{ id: "cls-1", name: "Section A", join_code: "JOIN1111", is_open: true }],
          meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
        }),
      ),
    );
  });

  it("displays locked-exam notice and disables editing when exam is published", async () => {
    server.use(
      http.get(`${API}/examiner/exams/exam-1`, () =>
        HttpResponse.json({
          data: {
            ...baseExam,
            status: "published",
            current_version_number: 1,
            is_editable: false,
          },
        }),
      ),
    );

    render(<ExamEditor examId="exam-1" />);
    expect(await screen.findByText(/locked/i)).toBeVisible();
    expect(screen.getByRole("button", { name: /Save Settings/i })).toBeDisabled();
  });

  it("displays structured validation issues (422) when pre-publish validation fails", async () => {
    const user = userEvent.setup();
    server.use(
      http.get(`${API}/examiner/exams/exam-1`, () => HttpResponse.json({ data: baseExam })),
      http.post(`${API}/examiner/exams/exam-1/validate`, () =>
        HttpResponse.json(
          {
            message: "Exam validation failed before publishing.",
            code: "exam_validation_failed",
            issues: [
              "Question 1 (High Value Products): reference SQL returned invalid columns.",
              "Attached dataset must pass MariaDB validation first.",
            ],
          },
          { status: 422 },
        ),
      ),
    );

    render(<ExamEditor examId="exam-1" />);
    await user.click(await screen.findByRole("tab", { name: /Validate & Publish/i }));
    await user.click(screen.getByRole("button", { name: /Run Validation/i }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent(/reference SQL returned invalid columns/i);
    expect(alert).toHaveTextContent(/Attached dataset must pass MariaDB validation first/i);
  });

  it("adds an MCQ question, publishes exam, and assigns classroom + releases results", async () => {
    const user = userEvent.setup();
    let examState = { ...baseExam };

    server.use(
      http.get(`${API}/examiner/exams/exam-1`, () => HttpResponse.json({ data: examState })),
      http.post(`${API}/examiner/exams/exam-1/questions`, async ({ request }) => {
        const body = await request.json();
        examState = {
          ...examState,
          questions: [
            ...examState.questions,
            {
              id: "q-2",
              exam_id: "exam-1",
              position: 2,
              question_type: body.question_type,
              grading_mode: body.grading_mode,
              title: body.title,
              prompt: body.prompt,
              points: String(body.points),
              options: body.options ?? [],
              test_cases: [],
              rubric: [],
            },
          ],
        };
        return HttpResponse.json({ data: examState.questions[1] }, { status: 201 });
      }),
      http.post(`${API}/examiner/exams/exam-1/publish`, () => {
        examState = {
          ...examState,
          status: "published",
          current_version_number: 1,
          is_editable: false,
        };
        return HttpResponse.json({ data: examState });
      }),
      http.post(`${API}/examiner/exams/exam-1/classrooms`, () => {
        const assigned = {
          id: "ce-1",
          classroom_id: "cls-1",
          classroom: { id: "cls-1", name: "Section A", join_code: "JOIN1111", is_open: true },
          exam_id: "exam-1",
          exam_version_id: "ver-1",
          version_number: 1,
          is_active: true,
          results_released_at: null,
          attempts_count: 0,
        };
        examState = { ...examState, classroom_exams: [assigned] };
        return HttpResponse.json({ data: assigned }, { status: 201 });
      }),
    );

    render(<ExamEditor examId="exam-1" />);
    await user.click(await screen.findByRole("tab", { name: /Questions/i }));
    await user.click(screen.getByRole("button", { name: /New Question/i }));
    await user.selectOptions(screen.getByLabelText("Question Type"), "mcq");
    await user.type(screen.getByLabelText("Question Title"), "Null Semantics");
    await user.type(screen.getByLabelText("Prompt"), "Which operator matches NULL?");
    await user.click(screen.getByRole("button", { name: /Save Question/i }));

    expect(await screen.findByText("Null Semantics")).toBeVisible();

    await user.click(screen.getByRole("tab", { name: /Validate & Publish/i }));
    await user.click(screen.getByRole("button", { name: /Publish Exam/i }));
    expect(await screen.findByText(/v1/i)).toBeVisible();

    await user.click(screen.getByRole("tab", { name: /Classrooms/i }));
    await user.selectOptions(screen.getByLabelText("Classroom"), "cls-1");
    await user.click(screen.getByRole("button", { name: /Assign to Classroom/i }));

    expect(await screen.findByText("Section A")).toBeVisible();
  });

  it("converts local datetime-local availability windows into UTC ISO-8601 strings before assigning", async () => {
    const user = userEvent.setup();
    let capturedPayload = null;

    server.use(
      http.get(`${API}/examiner/exams/exam-1`, () =>
        HttpResponse.json({
          data: {
            ...baseExam,
            status: "published",
            current_version_number: 1,
            is_editable: false,
          },
        }),
      ),
      http.post(`${API}/examiner/exams/exam-1/classrooms`, async ({ request }) => {
        capturedPayload = await request.json();
        return HttpResponse.json(
          {
            data: {
              id: "ce-1",
              classroom_id: "cls-1",
              classroom: { id: "cls-1", name: "Section A", join_code: "JOIN1111", is_open: true },
              exam_id: "exam-1",
              exam_version_id: "ver-1",
              version_number: 1,
              is_active: true,
              available_from: capturedPayload.available_from,
              available_until: capturedPayload.available_until,
              results_released_at: null,
              attempts_count: 0,
            },
          },
          { status: 201 },
        );
      }),
    );

    render(<ExamEditor examId="exam-1" />);
    await user.click(await screen.findByRole("tab", { name: /Classrooms/i }));
    await user.selectOptions(screen.getByLabelText("Classroom"), "cls-1");
    await user.type(screen.getByLabelText(/Available From/i), "2026-11-28T13:51");
    await user.type(screen.getByLabelText(/Available Until/i), "2026-11-29T13:51");
    await user.click(screen.getByRole("button", { name: /Assign to Classroom/i }));

    await waitFor(() => expect(capturedPayload).not.toBeNull());
    expect(capturedPayload.available_from).toBe(new Date("2026-11-28T13:51").toISOString());
    expect(capturedPayload.available_until).toBe(new Date("2026-11-29T13:51").toISOString());
  });
});
