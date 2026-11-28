import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "../test/msw/server";
import StudentSessionView from "../components/StudentSessionView";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

describe("StudentSessionView", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
    );
  });

  it("redirects to / when student session is unauthenticated (401)", async () => {
    server.use(
      http.get(`${API}/student/session`, () =>
        HttpResponse.json({ message: "Unauthenticated." }, { status: 401 }),
      ),
    );

    render(<StudentSessionView />);
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/"));
  });

  it("shows a closed-class notice when the classroom has been closed (403) and lets student leave cleanly", async () => {
    const user = userEvent.setup();
    let leftCalled = false;
    server.use(
      http.get(`${API}/student/session`, () =>
        HttpResponse.json({ message: "This class is currently closed.", code: "class_closed" }, { status: 403 }),
      ),
      http.post(`${API}/student/leave`, () => {
        leftCalled = true;
        return HttpResponse.json({ message: "Left class." });
      }),
    );

    render(<StudentSessionView />);
    expect(await screen.findByRole("alert")).toHaveTextContent("This class is currently closed.");
    await user.click(screen.getByRole("button", { name: "Leave Class" }));
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/"));
    expect(leftCalled).toBe(true);
  });

  it("renders active class and student display name and handles Leave Class", async () => {
    const user = userEvent.setup();
    server.use(
      http.get(`${API}/student/session`, () =>
        HttpResponse.json({
          data: {
            id: "sess-1",
            display_name: "Ada Lovelace",
            expires_at: "2026-11-28T12:00:00Z",
            classroom: {
              id: "class-1",
              name: "SQL Practical 1",
              join_code: "JOIN2345",
              is_open: true,
            },
          },
        }),
      ),
      http.get(`${API}/student/exams`, () => HttpResponse.json({ data: [] })),
      http.post(`${API}/student/leave`, () => HttpResponse.json({ message: "Left class." })),
    );

    render(<StudentSessionView />);
    expect(await screen.findByText("Ada Lovelace")).toBeVisible();
    expect(screen.getByText("SQL Practical 1")).toBeVisible();
    expect(screen.getByText("JOIN2345")).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Leave Class" }));
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/"));
  });

  it("lists assigned exams and starts an exam attempt into /student/exams/[attemptId]", async () => {
    const user = userEvent.setup();
    server.use(
      http.get(`${API}/student/session`, () =>
        HttpResponse.json({
          data: {
            id: "sess-1",
            display_name: "Ada Lovelace",
            expires_at: "2026-11-28T12:00:00Z",
            classroom: { id: "class-1", name: "SQL Practical 1", join_code: "JOIN2345", is_open: true },
          },
        }),
      ),
      http.get(`${API}/student/exams`, () =>
        HttpResponse.json({
          data: [
            {
              id: "ce-1",
              title: "Midterm SQL Practical",
              duration_minutes: 45,
              question_count: 3,
              total_points: "30.00",
              attempt: null,
            },
          ],
        }),
      ),
      http.post(`${API}/student/exams/ce-1/start`, () =>
        HttpResponse.json({ data: { id: "att-new" } }, { status: 201 }),
      ),
    );

    render(<StudentSessionView />);
    expect(await screen.findByText("Midterm SQL Practical")).toBeVisible();
    await user.click(screen.getByRole("button", { name: /Start Exam/i }));
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/student/exams/att-new"));
  });
});
