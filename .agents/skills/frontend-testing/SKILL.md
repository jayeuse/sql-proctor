---
name: frontend-testing
description: Use when writing, reviewing, or repairing tests for the SQL-Proctor Next.js frontend with Vitest and React Testing Library. Covers React components, hooks, form validation, UI state, Axios API calls, loading, empty, error and success states, authentication states, accessibility, test isolation, and the Next.js App Router client/server boundary. Triggers on Vitest, React Testing Library, jsdom, `@testing-library/react`, `@testing-library/user-event`, `@testing-library/jest-dom`, MSW, component test, hook test, form test, login form test, "test this screen", `*.test.js`, `*.test.jsx`, or `__tests__/` under `frontend/sql-proctor-ui/`.
---

# Frontend Testing (Vitest, React Testing Library, Next.js 16)

## Purpose

Define how the SQL-Proctor frontend is tested below the browser: React components, hooks, forms, UI states, API interaction through `lib/api.js`, authentication states, and accessibility. The tests use Vitest with jsdom and React Testing Library (RTL), and they assert what a user can see and do.

This skill does not replace [test-driven-development](../test-driven-development/SKILL.md), [negative-first-testing](../negative-first-testing/SKILL.md), [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md), or [integration-testing](../integration-testing/SKILL.md). It adds the frontend unit and component layer that those skills leave open.

## Trigger conditions

Load this skill when a task does any of these:

1. Writes or changes a React component, page, layout, form, hook, or client-side data fetch in `frontend/sql-proctor-ui/`.
2. Writes a test file named `*.test.js`, `*.test.jsx`, or one under `__tests__/`.
3. Repairs a failing or flaky Vitest test.
4. Needs a decision on the test layer: Vitest component test, Playwright E2E, or Laravel feature test.
5. Reviews frontend test quality, query choice, mocking, or coverage of negative cases.

Do not load this skill for Laravel PHPUnit tests. Use [laravel-testing](../laravel-testing/SKILL.md) for those.

## Scope

In scope:

1. Vitest unit and component tests for the Next.js App Router frontend.
2. Synchronous Server Components and Client Components.
3. Client-side forms, validation display, loading, empty, error, and success states.
4. Hooks with observable behavior, tested through a component or `renderHook`.
5. API interaction through the single Axios instance in `frontend/sql-proctor-ui/lib/api.js`.
6. Authentication states as the UI sees them: signed in, signed out, `401`, `403`.
7. Accessibility assertions that a DOM test can prove: accessible name, role, focus, keyboard path, and error announcement.
8. Test isolation: cleanup, handler reset, mock restore, and order-independent tests.

Out of scope:

1. Async Server Components. Vitest does not support them. Test those with [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md).
2. Browser-only behavior: real navigation, cookie and CSRF exchange, layout, responsive widths, visible focus rings, and screenshots. Use [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md) and `build-web-apps:frontend-testing-debugging`.
3. Cross-application contracts between Next.js, Laravel, PostgreSQL, and MariaDB. Use [integration-testing](../integration-testing/SKILL.md).
4. Authorization enforcement. The Laravel feature tests own that. A frontend test proves only how the UI reacts to a response.
5. Performance measurement. Use `vercel-react-best-practices` for code guidance.

### Required test stack (not installed yet)

As of 2026-11-19, Vitest, `@testing-library/react`, `@testing-library/dom`, `@testing-library/jest-dom`, `@testing-library/user-event`, jsdom, MSW, and Playwright are **not installed** in `frontend/sql-proctor-ui/`. The current `package.json` has only Next.js, React, React DOM, Axios, Monaco, ESLint, and their peers.

1. Install the stack only inside a task that the Human Owner authorizes, and follow the approved install path in [docs/agent-engineering.md](../../../docs/agent-engineering.md).
2. Pin the exact versions in `package.json` at install time and record them in [docs/agent-engineering.md](../../../docs/agent-engineering.md). This skill states no version number, because the current stable line changes.
3. The required packages are:
   - `vitest`
   - `jsdom`
   - `@vitejs/plugin-react` (the Next.js official guide includes it)
   - `@testing-library/react`
   - `@testing-library/dom` (version-matched peer of `@testing-library/react`)
   - `@testing-library/jest-dom`
   - `@testing-library/user-event`
   - `msw` (preferred network mock)
4. Do not install anything while following this skill. Installation is a separate, authorized task.

The target configuration below follows the official Next.js Vitest guide, with one project addition: a predictable API origin for every test. Create it only after installation is authorized.

```js
// frontend/sql-proctor-ui/vitest.config.js
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    setupFiles: ["./vitest.setup.js"],
    env: {
      NEXT_PUBLIC_API_URL: "http://localhost:8000/api",
    },
  },
});
```

`test.env` assigns `process.env` before the tests run, so `lib/api.js` and every handler URL see the same origin.

```js
// frontend/sql-proctor-ui/vitest.setup.js
import "@testing-library/jest-dom/vitest";
import { afterAll, afterEach, beforeAll, vi } from "vitest";
import { cleanup } from "@testing-library/react";
import { server } from "./test/msw/server";

beforeAll(() => server.listen({ onUnhandledRequest: "error" }));

afterEach(() => {
  cleanup();
  server.resetHandlers();
  vi.restoreAllMocks();
  vi.useRealTimers();
});

afterAll(() => server.close());
```

## Required workflow

1. Read the feature's completion criteria and the component's contract: props, visible text, roles, labels, and the states it can show.
2. Choose the layer:
   - Synchronous Server Component or Client Component → Vitest.
   - Async Server Component, request data, navigation, cookies, or real browser behavior → E2E.
   - Authorization rule or API contract → Laravel feature test, not this skill.
3. Write the test matrix in the negative-first order listed below. Use [docs/templates/test-matrix.md](../../../docs/templates/test-matrix.md).
4. Write the first test. Run it. Watch it fail for the expected reason: the behavior is missing, not a typo or a bad selector.
5. Write the smallest production change that makes it pass. Run the test again. Do not weaken or delete the test.
6. Add the accessibility assertions for each interactive element that the change touches.
7. Run the focused file. Then run the frontend test batch that the task assigns. The parent runs the full batch.
8. Record the command, working directory, exit status, and real result in the task Notes. Write `NOT RUN` for a check that did not run.

```
cd frontend/sql-proctor-ui
npx vitest run __tests__/login-form.test.jsx
```

Use the exact test script that `package.json` gains at installation time. Do not claim a command passed unless it ran.

## Test categories

| Category | What the test proves | Typical assertion |
|---|---|---|
| Components | The rendered output a user sees for each input state | `screen.getByRole("heading", { name: "Exams" })` |
| Hooks | The observable result of a hook: returned state and rendered effect | `expect(result.current.error).toBe(...)` or assert through a probe component |
| Form validation | Missing, invalid, and boundary input is refused with an accessible message | `screen.findByText("Enter your email address.")` |
| UI state | Loading, empty, error, success, disabled, and pending states | `screen.getByRole("status")`, `toBeDisabled()` |
| API interaction | The request goes through `lib/api.js` to the documented URL with the expected method and body | MSW handler receives the request; response renders |
| Error handling | `401`, `403`, `404`, `422`, `500`, and network failure map to the intended message | `screen.findByRole("alert")` has the mapped text |
| Loading states | Pending feedback shows, then clears on every settlement path | status present while pending, absent after error or success |
| Authentication behavior | Signed-in, signed-out, expired session, and forbidden responses drive the UI | 401 moves to the signed-out state; 403 shows the access message |
| Accessibility | Accessible name, role, focus, keyboard path, and error announcement | `toHaveAccessibleName`, `toHaveFocus`, `user.tab()` |
| Client/server boundary | Synchronous components render; async ones do not enter Vitest | sync component renders; async Server Component test is routed to E2E |
| Test isolation | Tests pass alone and in any order | fresh handlers, cleanup, restored mocks, unique data |

## Rules

1. **Test behavior, not implementation.** Query with `screen.getByRole`, `getByLabelText`, `getByText`, and their `findBy*` and `queryBy*` forms. Do not assert component internals, private state, prop names, effect counts, or CSS class names. Do not snapshot whole components.
2. **Use `screen` and `userEvent`.** Call `userEvent.setup()` once per test. Use `await user.click(...)`, `await user.type(...)`, `await user.tab()`, and `await user.keyboard(...)`. Use `fireEvent` only for an event that `user-event` cannot express, and record the reason in a comment.
3. **Negative-first order.** Design, write, and run the failure cases before the success case, in the fixed order of [negative-first-testing](../negative-first-testing/SKILL.md). At the frontend layer that order becomes:
   1. invalid input, including a missing required value
   2. unauthorized (`401`)
   3. forbidden (`403`)
   4. invalid state (the action is not allowed in the current state)
   5. missing resource (`404`)
   6. duplicate operation, when the UI can send twice
   7. boundary (empty list, maximum length, first and last item)
   8. API or infrastructure failure (`500`, malformed body, network error)
   9. timeout
   10. success

   Frontend tests do not own the security-violation, concurrency, resource-exhaustion, or data-integrity categories. Route those to [negative-first-testing](../negative-first-testing/SKILL.md) and [integration-testing](../integration-testing/SKILL.md). Do not write the success case first.
4. **Mock the network boundary, not the component.** Prefer MSW with `setupServer` from `msw/node`. Build the handler URL from `NEXT_PUBLIC_API_URL` so the real `lib/api.js` instance and its headers, timeout, and credentials run. Call `server.resetHandlers()` after every test and start the server with `onUnhandledRequest: "error"` so an unexpected request fails the test. Use `server.use(...)` for per-test overrides. Mocking `lib/api.js` or an Axios adapter is a documented fallback only when interception is impossible; record the reason next to the mock. Never mock React, RTL, `user-event`, or the component under test.
5. **Use the required stack.** Vitest with `environment: "jsdom"`, a setup file, `@testing-library/react`, `@testing-library/jest-dom/vitest`, and `@testing-library/user-event`. `jest-dom` matchers come from the setup file, not from per-test imports.
6. **Respect the client/server boundary.**
   - A Client Component has `"use client"` and is tested directly with RTL.
   - A synchronous Server Component with no request data is tested directly with RTL.
   - An async Server Component `await`s data or request APIs such as `params`, `searchParams`, `cookies()`, or `headers()`. **Vitest does not support async Server Components. Route them to E2E.** Do not wrap one in a promise trick or render it in a Vitest test.
   - Do not unit-test Next.js internals: routing, caching, streaming, `next/font`, or cookies. Test your component's contract, and prove the integration in E2E.
7. **Cover all four request states for every API-backed component.** Assert that the loading state shows, then clears on error, empty, timeout, and success. Assert the accessible error text, not a blank screen or a console message.
8. **Model authentication from the UI's point of view.** Test signed in, signed out, expired session (`401` mid-action), and forbidden (`403`). Assert the UI moves to the correct state and does not retry forever. A passing UI test is not proof of authorization; the server check stays in the Laravel tests.
9. **Make accessibility assertions.** Assert each control's role and accessible name, each input's label, the error announcement (`role="alert"` or a polite live region), focus placement after a failed submit, and the keyboard path to submit and cancel. Visible focus rings, contrast, and reduced motion are browser checks; route them to E2E or the manual accessibility check.
10. **Keep every test isolated.** Clean up the DOM, reset handlers, restore mocks and timers, and never depend on test order or shared mutable module state. Each test creates the data it needs. Do not share a rendered component or a `user` instance between tests.
11. **JavaScript only.** Use `.js` and `.jsx` for test files and JSX. Do not introduce `.tsx`, TypeScript, or a second test runner.
12. **No live API and no secrets.** Never call the real Laravel API from a Vitest test. Handlers return fixed JSON and never contain a real token, password, or `.env` value.
13. **Keep time deterministic.** Prefer `await screen.findBy...` and `waitFor` over sleeps. A timeout test sets a short per-request timeout (for example `api.defaults.timeout = 20`) and restores it. Use fake timers only when the behavior needs them, and restore real timers after the test.
14. **Do not duplicate the other skills.** The red-green-refactor cycle belongs to [test-driven-development](../test-driven-development/SKILL.md). The failure catalog belongs to [negative-first-testing](../negative-first-testing/SKILL.md). Browser flows belong to [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md).

## Examples

### Example 1: Login form, negative-first

Component contract: a Client Component `app/login/login-form.jsx` with an Email field, a Password field, a "Sign in" button, a loading status (`role="status"`), and an error region (`role="alert"`). It calls `api.post("/login", ...)` from `lib/api.js` and calls the `onSignedIn` prop on success.

```jsx
// frontend/sql-proctor-ui/__tests__/login-form.test.jsx
import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse, delay } from "msw";
import { server } from "../test/msw/server";
import LoginForm from "../app/login/login-form";

const API = process.env.NEXT_PUBLIC_API_URL;
let loginRequests;

beforeEach(() => {
  loginRequests = 0;
  server.use(
    http.post(`${API}/login`, async ({ request }) => {
      loginRequests += 1;
      const body = await request.json();
      if (body.password !== "correct-horse") {
        return HttpResponse.json(
          {
            message: "The provided credentials are incorrect.",
            errors: { email: ["The provided credentials are incorrect."] },
          },
          { status: 422 },
        );
      }
      return HttpResponse.json({ id: 1, name: "Ada" });
    }),
  );
});

function renderForm() {
  const user = userEvent.setup();
  const onSignedIn = vi.fn();
  render(<LoginForm onSignedIn={onSignedIn} />);
  return { user, onSignedIn };
}

describe("LoginForm", () => {
  it("refuses an empty email and sends no request", async () => {
    const { user } = renderForm();

    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Enter your email address.");
    expect(loginRequests).toBe(0);
  });

  it("refuses an invalid email and sends no request", async () => {
    const { user } = renderForm();

    await user.type(screen.getByLabelText("Email"), "not-an-email");
    await user.type(screen.getByLabelText("Password"), "correct-horse");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Enter a valid email address.");
    expect(loginRequests).toBe(0);
  });

  it("shows the validation error when the password is wrong", async () => {
    const { user } = renderForm();

    await user.type(screen.getByLabelText("Email"), "ada@example.com");
    await user.type(screen.getByLabelText("Password"), "wrong-password");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The provided credentials are incorrect.",
    );
    expect(screen.getByRole("button", { name: "Sign in" })).toBeEnabled();
    expect(screen.queryByText("Signing you in")).not.toBeInTheDocument();
  });

  it("signs in, keeps the pending state visible, then clears it", async () => {
    const { user, onSignedIn } = renderForm();
    server.use(
      http.post(`${API}/login`, async () => {
        await delay(100);
        return HttpResponse.json({ id: 1, name: "Ada" });
      }),
    );

    await user.type(screen.getByLabelText("Email"), "ada@example.com");
    await user.type(screen.getByLabelText("Password"), "correct-horse");
    await user.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("status")).toHaveTextContent("Signing you in");
    expect(await screen.findByText("Signed in")).toBeVisible();
    expect(screen.queryByText("Signing you in")).not.toBeInTheDocument();
    expect(onSignedIn).toHaveBeenCalledWith({ id: 1, name: "Ada" });
  });
});
```

### Example 2: API-backed list, negative-first

Component contract: a Client Component `QuestionList` fetches `GET /exams/42/questions` through `lib/api.js` and shows a loading status, an empty message, an error alert, or a list of question titles.

```jsx
// frontend/sql-proctor-ui/__tests__/question-list.test.jsx
import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { http, HttpResponse, delay } from "msw";
import { server } from "../test/msw/server";
import api from "../lib/api";
import QuestionList from "../app/exams/question-list";

const API = process.env.NEXT_PUBLIC_API_URL;
const QUESTIONS_URL = `${API}/exams/42/questions`;

function renderList() {
  render(<QuestionList examId={42} />);
  return screen.findByRole("heading", { name: "Questions" });
}

describe("QuestionList", () => {
  it("moves to the signed-out state when the session has expired", async () => {
    server.use(
      http.get(QUESTIONS_URL, () => HttpResponse.json({ message: "Unauthenticated." }, { status: 401 })),
    );

    await renderList();

    expect(await screen.findByRole("alert")).toHaveTextContent("Your session ended. Sign in again.");
    expect(screen.queryByText("Loading questions")).not.toBeInTheDocument();
  });

  it("shows the access message when the user is forbidden", async () => {
    server.use(
      http.get(QUESTIONS_URL, () => HttpResponse.json({ message: "This action is unauthorized." }, { status: 403 })),
    );

    await renderList();

    expect(await screen.findByRole("alert")).toHaveTextContent("You do not have access to these questions.");
  });

  it("shows a not-found message when the exam is gone", async () => {
    server.use(
      http.get(QUESTIONS_URL, () => HttpResponse.json({ message: "Not found." }, { status: 404 })),
    );

    await renderList();

    expect(await screen.findByRole("alert")).toHaveTextContent("This exam no longer exists.");
  });

  it("shows the empty state when the exam has no questions", async () => {
    server.use(http.get(QUESTIONS_URL, () => HttpResponse.json({ data: [] })));

    await renderList();

    expect(await screen.findByText("No questions yet.")).toBeVisible();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("shows an accessible error and clears loading when the API fails", async () => {
    server.use(
      http.get(QUESTIONS_URL, () => HttpResponse.json({ message: "Server error." }, { status: 500 })),
    );

    await renderList();

    expect(await screen.findByRole("alert")).toHaveTextContent("Could not load questions.");
    expect(screen.queryByText("Loading questions")).not.toBeInTheDocument();
  });

  it("shows a timeout message when the request times out", async () => {
    const originalTimeout = api.defaults.timeout;
    api.defaults.timeout = 20;
    server.use(
      http.get(QUESTIONS_URL, async () => {
        await delay(200);
        return HttpResponse.json({ data: [] });
      }),
    );

    try {
      await renderList();
      expect(await screen.findByRole("alert")).toHaveTextContent("The request timed out. Try again.");
    } finally {
      api.defaults.timeout = originalTimeout;
    }
  });

  it("shows a loading status until the request settles", async () => {
    server.use(
      http.get(QUESTIONS_URL, async () => {
        await delay(50);
        return HttpResponse.json({ data: [{ id: 1, title: "Select all students" }] });
      }),
    );

    render(<QuestionList examId={42} />);

    expect(screen.getByRole("status")).toHaveTextContent("Loading questions");
    expect(await screen.findByRole("list", { name: "Questions" })).toBeVisible();
    expect(screen.queryByText("Loading questions")).not.toBeInTheDocument();
  });

  it("lists the questions when the request succeeds", async () => {
    server.use(
      http.get(QUESTIONS_URL, () =>
        HttpResponse.json({
          data: [
            { id: 1, title: "Select all students" },
            { id: 2, title: "Count passed attempts" },
          ],
        }),
      ),
    );

    await renderList();

    const items = await screen.findAllByRole("listitem");
    expect(items).toHaveLength(2);
    expect(items[0]).toHaveTextContent("Select all students");
    expect(items[1]).toHaveTextContent("Count passed attempts");
  });
});
```

The loading test uses a real 50 ms delay instead of fake timers. Keep delays short and local to the test.

### Example 3: Accessibility and keyboard path

```jsx
it("reaches every control with the keyboard and announces the error", async () => {
  const user = userEvent.setup();
  render(<LoginForm onSignedIn={vi.fn()} />);

  await user.tab();
  expect(screen.getByLabelText("Email")).toHaveFocus();
  await user.tab();
  expect(screen.getByLabelText("Password")).toHaveFocus();
  await user.tab();
  expect(screen.getByRole("button", { name: "Sign in" })).toHaveFocus();
  expect(screen.getByRole("button", { name: "Sign in" })).toHaveAccessibleName("Sign in");

  await user.click(screen.getByRole("button", { name: "Sign in" }));

  const alert = await screen.findByRole("alert");
  expect(alert).toHaveTextContent("Enter your email address.");
  expect(screen.getByLabelText("Email")).toHaveAccessibleDescription("Enter your email address.");
});
```

## References

1. Repository rules: [AGENTS.md](../../../AGENTS.md).
2. Architecture and boundaries: [docs/architecture.md](../../../docs/architecture.md).
3. API contract and error shapes: [docs/api-specification.md](../../../docs/api-specification.md).
4. The single Axios instance: [lib/api.js](../../../frontend/sql-proctor-ui/lib/api.js).
5. Approved installation and version record: [docs/agent-engineering.md](../../../docs/agent-engineering.md).
6. Test matrix template: [docs/templates/test-matrix.md](../../../docs/templates/test-matrix.md).
7. Next.js official Vitest guide: <https://nextjs.org/docs/app/guides/testing/vitest>.
8. React Testing Library: <https://testing-library.com/docs/react-testing-library/intro>.
9. `user-event`: <https://testing-library.com/docs/user-event/intro>.
10. `jest-dom`: <https://github.com/testing-library/jest-dom>.
11. MSW: <https://mswjs.io/docs/>.
12. Vitest: <https://vitest.dev/guide/>.

## Related skills

Project skills:

1. [test-driven-development](../test-driven-development/SKILL.md) for the Red-Green-Refactor cycle.
2. [negative-first-testing](../negative-first-testing/SKILL.md) for the failure catalog and ordering.
3. [e2e-playwright-testing](../e2e-playwright-testing/SKILL.md) for async Server Components and browser journeys.
4. [integration-testing](../integration-testing/SKILL.md) for the Next.js to Laravel contract.
5. [laravel-testing](../laravel-testing/SKILL.md) for the authorization and API tests that the UI cannot prove.

Global skills:

6. `build-web-apps:frontend-testing-debugging` for rendered browser QA, screenshots, and console checks.
7. `vercel-react-best-practices` for React and Next.js performance guidance.
8. `vercel-composition-patterns` for component API design that is easy to test.
9. `systematic-debugging` for a failing test or a reproduced defect.
