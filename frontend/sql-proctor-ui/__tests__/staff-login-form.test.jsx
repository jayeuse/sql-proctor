import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse, delay } from "msw";
import { server } from "../test/msw/server";
import StaffLoginForm from "../components/StaffLoginForm";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

describe("StaffLoginForm", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
    );
  });

  it("refuses empty credentials without calling the API", async () => {
    const user = userEvent.setup();
    let requests = 0;
    server.use(
      http.post(`${API}/auth/login`, () => {
        requests += 1;
        return HttpResponse.json({ data: {} });
      }),
    );

    render(<StaffLoginForm />);
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Enter your username and password.");
    expect(requests).toBe(0);
  });

  it("displays generic error on 401 invalid credentials", async () => {
    const user = userEvent.setup();
    server.use(
      http.post(`${API}/auth/login`, () =>
        HttpResponse.json(
          { message: "Invalid username or password.", code: "invalid_credentials" },
          { status: 401 },
        ),
      ),
    );

    render(<StaffLoginForm />);
    await user.type(screen.getByLabelText("Username"), "examiner1");
    await user.type(screen.getByLabelText("Password"), "wrong-password-123");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Invalid username or password.");
    expect(screen.getByRole("button", { name: "Sign in" })).toBeEnabled();
  });

  it("displays lockout alert on 429 rate limit", async () => {
    const user = userEvent.setup();
    server.use(
      http.post(`${API}/auth/login`, () =>
        HttpResponse.json(
          { message: "Too many login attempts. Try again later.", code: "too_many_attempts" },
          { status: 429 },
        ),
      ),
    );

    render(<StaffLoginForm />);
    await user.type(screen.getByLabelText("Username"), "examiner1");
    await user.type(screen.getByLabelText("Password"), "wrong-password-123");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Too many login attempts.");
  });

  it("shows pending status and redirects admin to /admin and examiner to /examiner", async () => {
    const user = userEvent.setup();
    server.use(
      http.post(`${API}/auth/login`, async ({ request }) => {
        await delay(40);
        const body = await request.json();
        return HttpResponse.json({
          data: {
            id: 1,
            username: body.username,
            role: body.username === "admin" ? "admin" : "examiner",
            is_active: true,
          },
        });
      }),
    );

    const { unmount } = render(<StaffLoginForm />);
    await user.type(screen.getByLabelText("Username"), "admin");
    await user.type(screen.getByLabelText("Password"), "SuperStrongPassword123!");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(screen.getByRole("status")).toHaveTextContent("Signing in");
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/admin"));

    unmount();
    pushMock.mockReset();

    render(<StaffLoginForm />);
    await user.type(screen.getByLabelText("Username"), "prof_sql");
    await user.type(screen.getByLabelText("Password"), "SuperStrongPassword123!");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/examiner"));
  });
});
