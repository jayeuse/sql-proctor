import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "../test/msw/server";
import AdminDashboard from "../components/AdminDashboard";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

describe("AdminDashboard Exams Oversight", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 1, username: "admin1", role: "admin" } }),
      ),
      http.get(`${API}/admin/examiners`, () =>
        HttpResponse.json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
      ),
    );
  });

  it("requires a reason before allowing force-unpublish of a published exam", async () => {
    const user = userEvent.setup();
    let forceCalled = false;

    server.use(
      http.get(`${API}/admin/exams`, () =>
        HttpResponse.json({
          data: [
            {
              id: "exam-pub",
              title: "Leaked Practical Exam",
              status: "published",
              current_version_number: 1,
              total_points: "20.00",
              questions_count: 2,
              attempts_count: 3,
              examiner: { id: 2, username: "examiner1", name: "Examiner One" },
            },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        }),
      ),
      http.post(`${API}/admin/exams/exam-pub/force-unpublish`, () => {
        forceCalled = true;
        return HttpResponse.json({ data: {} });
      }),
    );

    render(<AdminDashboard />);
    await user.click(await screen.findByRole("tab", { name: "Exams" }));

    expect(await screen.findByText("Leaked Practical Exam")).toBeVisible();
    await user.click(screen.getByRole("button", { name: /Force Unpublish/i }));
    await user.click(screen.getByRole("button", { name: /Confirm Force Unpublish/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/reason/i);
    expect(forceCalled).toBe(false);
  });

  it("force-unpublishes a published exam when a valid reason is provided", async () => {
    const user = userEvent.setup();
    let capturedBody = null;
    let currentStatus = "published";

    server.use(
      http.get(`${API}/admin/exams`, () =>
        HttpResponse.json({
          data: [
            {
              id: "exam-pub",
              title: "Leaked Practical Exam",
              status: currentStatus,
              current_version_number: 1,
              total_points: "20.00",
              questions_count: 2,
              attempts_count: 3,
              examiner: { id: 2, username: "examiner1", name: "Examiner One" },
            },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        }),
      ),
      http.post(`${API}/admin/exams/exam-pub/force-unpublish`, async ({ request }) => {
        capturedBody = await request.json();
        currentStatus = "unpublished";
        return HttpResponse.json({
          data: {
            id: "exam-pub",
            title: "Leaked Practical Exam",
            status: "unpublished",
          },
        });
      }),
    );

    render(<AdminDashboard />);
    await user.click(await screen.findByRole("tab", { name: "Exams" }));
    await user.click(await screen.findByRole("button", { name: /Force Unpublish/i }));
    await user.type(screen.getByLabelText("Reason"), "Policy violation during exam window.");
    await user.click(screen.getByRole("button", { name: /Confirm Force Unpublish/i }));

    await waitFor(() =>
      expect(capturedBody).toEqual({
        reason: "Policy violation during exam window.",
        force_close_active: true,
      }),
    );
  });
});
