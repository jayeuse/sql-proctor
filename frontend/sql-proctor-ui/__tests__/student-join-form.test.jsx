import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "../test/msw/server";
import StudentJoinForm from "../components/StudentJoinForm";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

describe("StudentJoinForm", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
    );
  });

  it("rejects blank fields locally without calling the API", async () => {
    const user = userEvent.setup();
    let called = false;
    server.use(
      http.post(`${API}/student/join`, () => {
        called = true;
        return HttpResponse.json({ data: {} }, { status: 201 });
      }),
    );

    render(<StudentJoinForm />);
    await user.click(screen.getByRole("button", { name: "Join class" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Enter your display name and class code.");
    expect(called).toBe(false);
  });

  it("shows conflict message when display name is already taken (409)", async () => {
    const user = userEvent.setup();
    server.use(
      http.post(`${API}/student/join`, () =>
        HttpResponse.json(
          { message: "That display name is already in use in this class.", code: "display_name_taken" },
          { status: 409 },
        ),
      ),
    );

    render(<StudentJoinForm />);
    await user.type(screen.getByLabelText("Display name"), "Ada Lovelace");
    await user.type(screen.getByLabelText("Class code"), "ABCD2345");
    await user.click(screen.getByRole("button", { name: "Join class" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "That display name is already in use in this class.",
    );
  });

  it("shows not-found message for invalid class code (404)", async () => {
    const user = userEvent.setup();
    server.use(
      http.post(`${API}/student/join`, () =>
        HttpResponse.json(
          { message: "Class code not found.", code: "class_not_found" },
          { status: 404 },
        ),
      ),
    );

    render(<StudentJoinForm />);
    await user.type(screen.getByLabelText("Display name"), "Ada Lovelace");
    await user.type(screen.getByLabelText("Class code"), "ZZZZ9999");
    await user.click(screen.getByRole("button", { name: "Join class" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Class code not found.");
  });

  it("joins class with normalized code and redirects to /student", async () => {
    const user = userEvent.setup();
    let receivedBody = null;
    server.use(
      http.post(`${API}/student/join`, async ({ request }) => {
        receivedBody = await request.json();
        return HttpResponse.json(
          {
            data: {
              id: "session-uuid",
              display_name: "Ada Lovelace",
              classroom: { id: "class-uuid", name: "SQL 101", join_code: "ABCD2345", is_open: true },
            },
          },
          { status: 201 },
        );
      }),
    );

    render(<StudentJoinForm />);
    await user.type(screen.getByLabelText("Display name"), "Ada Lovelace");
    await user.type(screen.getByLabelText("Class code"), "abcd2345");
    await user.click(screen.getByRole("button", { name: "Join class" }));

    await screen.findByRole("button", { name: "Join class" });
    expect(receivedBody).toEqual({
      display_name: "Ada Lovelace",
      class_code: "ABCD2345",
    });
    expect(pushMock).toHaveBeenCalledWith("/student");
  });

  it("strips surrounding spaces when pasting an 8-character class code", async () => {
    const user = userEvent.setup();
    let receivedBody = null;
    server.use(
      http.post(`${API}/student/join`, async ({ request }) => {
        receivedBody = await request.json();
        return HttpResponse.json(
          {
            data: {
              id: "session-uuid",
              display_name: "Ada Lovelace",
              classroom: { id: "class-uuid", name: "SQL 101", join_code: "ABCD2345", is_open: true },
            },
          },
          { status: 201 },
        );
      }),
    );

    render(<StudentJoinForm />);
    await user.type(screen.getByLabelText("Display name"), "Ada Lovelace");
    await user.click(screen.getByLabelText("Class code"));
    await user.paste("  abcd2345  ");
    await user.click(screen.getByRole("button", { name: "Join class" }));

    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/student"));
    expect(receivedBody).toEqual({
      display_name: "Ada Lovelace",
      class_code: "ABCD2345",
    });
  });
});
