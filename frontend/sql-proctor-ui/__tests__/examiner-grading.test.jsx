import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "../test/msw/server";
import ExamAttemptsList from "../components/ExamAttemptsList";
import AttemptGradingView from "../components/AttemptGradingView";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
  usePathname: () => "/examiner/attempts/att-1",
  useParams: () => ({ examId: "exam-1", attemptId: "att-1" }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

const sampleDetail = {
  id: "att-1",
  classroom_exam_id: "ce-1",
  exam_id: "exam-1",
  exam_title: "SQL Midterm 1",
  passing_score_percent: "60.00",
  student_display_name: "Ada Lovelace",
  classroom: { id: "cls-1", name: "Section A" },
  results_released_at: null,
  status: "submitted",
  grading_status: "needs_review",
  auto_score: "8.00",
  manual_score: null,
  final_score: "8.00",
  max_score: "15.00",
  passed: null,
  items: [
    {
      question: {
        id: "q-1",
        position: 1,
        question_type: "sql_select",
        grading_mode: "auto",
        title: "High Value Products",
        prompt: "Select products costing over 20.",
        points: "10.00",
        reference_sql: "SELECT id FROM products WHERE price > 20;",
        rubric: [],
      },
      answer: {
        id: "ans-1",
        attempt_id: "att-1",
        question_id: "q-1",
        submitted_sql: "SELECT id FROM products WHERE price >= 20;",
        submitted_text: null,
        selected_option_ids: [],
        auto_score: "8.00",
        manual_score: null,
        final_score: "8.00",
        max_points: "10.00",
        grading_status: "auto_graded",
        test_results: [
          {
            test_case_id: "tc-1",
            name: "Visible check",
            is_hidden: false,
            passed: true,
            points_earned: "8.00",
            weight_points: "8.00",
            execution_ms: 12,
            message: "Matched reference",
          },
        ],
        examiner_feedback: null,
        override_reason: null,
      },
    },
    {
      question: {
        id: "q-2",
        position: 2,
        question_type: "short_answer",
        grading_mode: "manual",
        title: "Index Choice",
        prompt: "Explain why an index helps.",
        points: "5.00",
        rubric: [{ id: "r1", label: "Accuracy", max_points: 5 }],
      },
      answer: {
        id: "ans-2",
        attempt_id: "att-1",
        question_id: "q-2",
        submitted_sql: null,
        submitted_text: "B-tree narrows range scans.",
        selected_option_ids: [],
        auto_score: null,
        manual_score: null,
        final_score: "0.00",
        max_points: "5.00",
        grading_status: "needs_review",
        test_results: [],
        examiner_feedback: null,
        override_reason: null,
      },
    },
  ],
  query_runs: [
    {
      id: "run-1",
      question_id: "q-1",
      sql_text: "SELECT id FROM products;",
      status: "succeeded",
      row_count: 2,
      execution_ms: 14,
      error_message: null,
      created_at: "2026-11-28T05:30:00Z",
    },
  ],
};

describe("ExamAttemptsList", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "examiner1", role: "examiner" } }),
      ),
    );
  });

  it("renders attempts list and links to grading review", async () => {
    server.use(
      http.get(`${API}/examiner/exams/exam-1/attempts`, () =>
        HttpResponse.json({
          data: [
            {
              id: "att-1",
              student_display_name: "Ada Lovelace",
              classroom: { id: "cls-1", name: "Section A" },
              status: "submitted",
              grading_status: "needs_review",
              final_score: "8.00",
              max_score: "15.00",
              passed: null,
            },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        }),
      ),
    );

    render(<ExamAttemptsList examId="exam-1" />);
    expect(await screen.findByText("Ada Lovelace")).toBeVisible();
    expect(screen.getByRole("link", { name: /Grade \/ Review/i })).toHaveAttribute(
      "href",
      "/examiner/attempts/att-1",
    );
  });
});

describe("AttemptGradingView", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "examiner1", role: "examiner" } }),
      ),
    );
  });

  it("blocks overriding an auto-graded answer without an override_reason", async () => {
    const user = userEvent.setup();
    let patchCalled = false;
    server.use(
      http.get(`${API}/examiner/attempts/att-1`, () => HttpResponse.json({ data: sampleDetail })),
      http.patch(`${API}/examiner/attempts/att-1/answers/ans-1/grade`, () => {
        patchCalled = true;
        return HttpResponse.json({ data: {} });
      }),
    );

    render(<AttemptGradingView attemptId="att-1" />);
    expect(await screen.findByText("High Value Products")).toBeVisible();

    const saveButtons = screen.getAllByRole("button", { name: /Save Grade/i });
    await user.click(saveButtons[0]);

    expect(await screen.findByRole("alert")).toHaveTextContent(/override reason/i);
    expect(patchCalled).toBe(false);
  });

  it("grades manual short_answer and finalizes attempt score", async () => {
    const user = userEvent.setup();
    let currentDetail = structuredClone(sampleDetail);
    let gradedPayload = null;

    server.use(
      http.get(`${API}/examiner/attempts/att-1`, () => HttpResponse.json({ data: currentDetail })),
      http.patch(`${API}/examiner/attempts/att-1/answers/ans-2/grade`, async ({ request }) => {
        gradedPayload = await request.json();
        currentDetail = {
          ...currentDetail,
          manual_score: "5.00",
          final_score: "13.00",
        };
        return HttpResponse.json({ data: currentDetail.items[1].answer });
      }),
      http.post(`${API}/examiner/attempts/att-1/finalize`, () => {
        currentDetail = {
          ...currentDetail,
          grading_status: "finalized",
          passed: true,
        };
        return HttpResponse.json({ data: currentDetail });
      }),
    );

    render(<AttemptGradingView attemptId="att-1" />);
    expect(await screen.findByText("Index Choice")).toBeVisible();

    const scoreInputs = screen.getAllByLabelText(/Manual Score/i);
    await user.clear(scoreInputs[1]);
    await user.type(scoreInputs[1], "5");

    const feedbackInputs = screen.getAllByLabelText(/Feedback/i);
    await user.type(feedbackInputs[1], "Accurate explanation.");

    const saveButtons = screen.getAllByRole("button", { name: /Save Grade/i });
    await user.click(saveButtons[1]);

    await waitFor(() => expect(gradedPayload?.manual_score).toBe(5));

    await user.click(screen.getByRole("button", { name: /Finalize Attempt/i }));
    expect(await screen.findByText(/Finalized/i)).toBeVisible();
    expect(screen.getByText(/Passed/i)).toBeVisible();
  });
});
