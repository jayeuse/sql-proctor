import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse, delay } from "msw";
import { server } from "../test/msw/server";
import ExaminerDashboard from "../components/ExaminerDashboard";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

describe("ExaminerDashboard", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
    );
  });

  it("redirects unauthenticated visitors (401) to /login", async () => {
    server.use(
      http.get(`${API}/user`, () => HttpResponse.json({ message: "Unauthenticated." }, { status: 401 })),
    );

    render(<ExaminerDashboard />);
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/login"));
  });

  it("creates a classroom, toggles open/close, and regenerates its join code", async () => {
    const user = userEvent.setup();
    let classes = [];

    server.use(
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "prof_sql", role: "examiner", is_active: true } }),
      ),
      http.get(`${API}/examiner/classes`, () =>
        HttpResponse.json({
          data: classes,
          meta: { current_page: 1, last_page: 1, total: classes.length, per_page: 50 },
          links: { prev: null, next: null },
        }),
      ),
      http.post(`${API}/examiner/classes`, async ({ request }) => {
        const body = await request.json();
        const created = {
          id: "class-1",
          name: body.name,
          join_code: "ABCD2345",
          is_open: true,
          active_students_count: 0,
        };
        classes = [created];
        return HttpResponse.json({ data: created }, { status: 201 });
      }),
      http.post(`${API}/examiner/classes/class-1/close`, () => {
        classes = [{ ...classes[0], is_open: false }];
        return HttpResponse.json({ data: classes[0] });
      }),
      http.post(`${API}/examiner/classes/class-1/regenerate-code`, () => {
        classes = [{ ...classes[0], join_code: "EFGH6789" }];
        return HttpResponse.json({ data: classes[0] });
      }),
    );

    render(<ExaminerDashboard />);
    expect(await screen.findByText("No classrooms yet.")).toBeVisible();

    await user.type(screen.getByLabelText("Classroom name"), "Database Lab 1");
    await user.click(screen.getByRole("button", { name: "Create classroom" }));

    expect(await screen.findByText("Database Lab 1")).toBeVisible();
    expect(screen.getByText("ABCD2345")).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Close Database Lab 1" }));
    expect(await screen.findByRole("button", { name: "Open Database Lab 1" }, { timeout: 3000 })).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Regenerate code for Database Lab 1" }));
    expect(await screen.findByText("EFGH6789", {}, { timeout: 3000 })).toBeVisible();
  });

  it("supports classroom expiry display, pagination, action busy lock, and 401 redirect", async () => {
    const user = userEvent.setup();
    let createdPayload = null;

    server.use(
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "prof_sql", role: "examiner", is_active: true } }),
      ),
      http.get(`${API}/examiner/classes`, ({ request }) => {
        const page = Number(new URL(request.url).searchParams.get("page") || "1");
        const row =
          page === 2
            ? { id: "c2", name: "Class Page 2", join_code: "PAGE2222", is_open: true, active_students_count: 1, code_expires_at: null }
            : { id: "c1", name: "Expired Lab", join_code: "EXP12345", is_open: true, active_students_count: 0, code_expires_at: "2020-01-01T00:00:00Z" };
        return HttpResponse.json({
          data: [row],
          meta: { current_page: page, last_page: 2, total: 2, per_page: 1 },
          links: { prev: null, next: null },
        });
      }),
      http.post(`${API}/examiner/classes`, async ({ request }) => {
        createdPayload = await request.json();
        return HttpResponse.json({
          data: { id: "c3", name: createdPayload.name, join_code: "NEW12345", is_open: true, active_students_count: 0, code_expires_at: createdPayload.code_expires_at ?? null },
        }, { status: 201 });
      }),
      http.post(`${API}/examiner/classes/c1/close`, async () => {
        await delay(50);
        return HttpResponse.json({ message: "Unauthenticated." }, { status: 401 });
      }),
    );

    render(<ExaminerDashboard />);
    expect(await screen.findByText("Expired Lab")).toBeVisible();
    expect(screen.getByText("Expired")).toBeVisible();

    await user.type(screen.getByLabelText("Classroom name"), "Timed SQL Lab");
    await user.type(screen.getByLabelText("Code expires at (optional)"), "2027-01-01T12:00");
    await user.click(screen.getByRole("button", { name: "Create classroom" }));
    await waitFor(() => expect(createdPayload?.code_expires_at).toBeTruthy());

    await user.click(screen.getByRole("button", { name: "Next page of classrooms" }));
    expect(await screen.findByText("Class Page 2")).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Previous page of classrooms" }));
    const closeBtn = await screen.findByRole("button", { name: "Close Expired Lab" });
    await user.click(closeBtn);
    expect(closeBtn).toBeDisabled();
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/login"));
  });
});
