import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse, delay } from "msw";
import { server } from "../test/msw/server";
import AdminDashboard from "../components/AdminDashboard";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

describe("AdminDashboard", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
    );
  });

  it("redirects unauthenticated users (401) to /login", async () => {
    server.use(
      http.get(`${API}/user`, () => HttpResponse.json({ message: "Unauthenticated." }, { status: 401 })),
    );

    render(<AdminDashboard />);
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/login"));
  });

  it("redirects examiner users to /examiner", async () => {
    server.use(
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "prof_sql", role: "examiner", is_active: true } }),
      ),
    );

    render(<AdminDashboard />);
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/examiner"));
  });

  it("creates an examiner with one-time password display, toggles status, and views audit logs", async () => {
    const user = userEvent.setup();
    let examiners = [
      { id: 10, username: "prof_existing", name: "Existing Prof", role: "examiner", is_active: true },
    ];

    server.use(
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 1, username: "admin", role: "admin", is_active: true } }),
      ),
      http.get(`${API}/admin/examiners`, () =>
        HttpResponse.json({
          data: examiners,
          meta: { current_page: 1, last_page: 1, total: examiners.length, per_page: 50 },
          links: { prev: null, next: null },
        }),
      ),
      http.post(`${API}/admin/examiners`, async ({ request }) => {
        const body = await request.json();
        const created = {
          id: 11,
          username: body.username,
          name: body.name || null,
          role: "examiner",
          is_active: true,
          generated_password: "OneTimeGenPassword123!",
        };
        examiners = [...examiners, created];
        return HttpResponse.json({ data: created }, { status: 201 });
      }),
      http.patch(`${API}/admin/examiners/10`, async ({ request }) => {
        const body = await request.json();
        examiners = examiners.map((ex) => (ex.id === 10 ? { ...ex, ...body } : ex));
        return HttpResponse.json({ data: examiners.find((ex) => ex.id === 10) });
      }),
      http.get(`${API}/audit-logs`, () =>
        HttpResponse.json({
          data: [
            {
              id: "audit-1",
              actor_type: "admin",
              actor_label: "admin",
              event_type: "examiner.created",
              target_type: "user",
              target_id: "11",
              ip_address: "127.0.0.1",
              request_id: "req-uuid",
              metadata: { username: "prof_new" },
              created_at: "2026-11-28T00:00:00Z",
            },
          ],
          meta: { current_page: 1, last_page: 1, total: 1, per_page: 50 },
          links: { prev: null, next: null },
        }),
      ),
    );

    render(<AdminDashboard />);
    expect(await screen.findByText("prof_existing")).toBeVisible();

    await user.type(screen.getByLabelText("Examiner username"), "prof_new");
    await user.click(screen.getByRole("button", { name: "Create examiner" }));

    expect(await screen.findByText("OneTimeGenPassword123!")).toBeVisible();
    expect(await screen.findByRole("cell", { name: "prof_new" })).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Deactivate prof_existing" }));
    await waitFor(() => {
      expect(screen.getByRole("button", { name: "Activate prof_existing" })).toBeVisible();
    });

    await user.click(screen.getByRole("tab", { name: "Audit Logs" }));
    expect(await screen.findByText("examiner.created")).toBeVisible();
    // Switching away from examiners clears sensitive one-time password banner
    expect(screen.queryByText("OneTimeGenPassword123!")).not.toBeInTheDocument();
  });

  it("paginates examiners, prevents double-click on row actions, filters audit dates, and redirects on 401", async () => {
    const user = userEvent.setup();
    let seenAuditParams = null;

    server.use(
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 1, username: "admin", role: "admin", is_active: true } }),
      ),
      http.get(`${API}/admin/examiners`, ({ request }) => {
        const page = Number(new URL(request.url).searchParams.get("page") || "1");
        const row =
          page === 2
            ? { id: 21, username: "prof_page2", name: "Page 2 Prof", role: "examiner", is_active: true }
            : { id: 20, username: "prof_page1", name: "Page 1 Prof", role: "examiner", is_active: true };
        return HttpResponse.json({
          data: [row],
          meta: { current_page: page, last_page: 2, total: 2, per_page: 1 },
          links: { prev: null, next: null },
        });
      }),
      http.patch(`${API}/admin/examiners/20`, async () => {
        await delay(50);
        return HttpResponse.json({ message: "Unauthenticated." }, { status: 401 });
      }),
      http.get(`${API}/audit-logs`, ({ request }) => {
        seenAuditParams = Object.fromEntries(new URL(request.url).searchParams.entries());
        return HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 },
          links: { prev: null, next: null },
        });
      }),
    );

    render(<AdminDashboard />);
    expect(await screen.findByText("prof_page1")).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Next page of examiners" }));
    expect(await screen.findByText("prof_page2")).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Previous page of examiners" }));
    expect(await screen.findByText("prof_page1")).toBeVisible();

    await user.click(screen.getByRole("tab", { name: "Audit Logs" }));
    await user.type(screen.getByLabelText("From timestamp filter"), "2026-11-28T00:00");
    await user.type(screen.getByLabelText("To timestamp filter"), "2026-11-28T23:59");
    await user.click(screen.getByRole("button", { name: "Apply" }));
    await waitFor(() => {
      expect(seenAuditParams?.from).toBeTruthy();
      expect(seenAuditParams?.to).toBeTruthy();
    });

    await user.click(screen.getByRole("tab", { name: "Examiners" }));
    const deactivateBtn = await screen.findByRole("button", { name: "Deactivate prof_page1" });
    await user.click(deactivateBtn);
    expect(deactivateBtn).toBeDisabled();
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/login"));
  });
});
