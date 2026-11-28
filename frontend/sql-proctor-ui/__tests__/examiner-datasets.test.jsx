import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "../test/msw/server";
import DatasetsManager from "../components/DatasetsManager";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
  usePathname: () => "/examiner/datasets",
}));

const API = process.env.NEXT_PUBLIC_API_URL;
const ORIGIN = API.replace(/\/api\/?$/, "");

const sampleDataset = {
  id: "ds-1",
  owner_id: 2,
  owner_username: "examiner1",
  name: "University Store",
  description: "Products and orders dataset",
  schema_sql: "CREATE TABLE products (id INT PRIMARY KEY, price DECIMAL(8,2));",
  seed_sql: "INSERT INTO products VALUES (1, 10.00), (2, 25.00);",
  schema_summary: null,
  is_shared: false,
  validated_at: null,
  exams_count: 0,
};

describe("DatasetsManager", () => {
  beforeEach(() => {
    pushMock.mockReset();
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
    );
  });

  it("redirects to / when user is unauthenticated (401)", async () => {
    server.use(
      http.get(`${API}/user`, () => HttpResponse.json({ message: "Unauthenticated." }, { status: 401 })),
    );

    render(<DatasetsManager />);
    await waitFor(() => expect(pushMock).toHaveBeenCalledWith("/"));
  });

  it("renders sanitized MariaDB validation error (422) when schema validation fails", async () => {
    const user = userEvent.setup();
    server.use(
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "examiner1", role: "examiner" } }),
      ),
      http.get(`${API}/examiner/datasets`, () =>
        HttpResponse.json({ data: [sampleDataset], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } }),
      ),
      http.post(`${API}/examiner/datasets/ds-1/validate`, () =>
        HttpResponse.json(
          {
            message: "SQL syntax error near 'CREAT' at line 1.",
            code: "dataset_validation_failed",
          },
          { status: 422 },
        ),
      ),
    );

    render(<DatasetsManager />);
    await user.click(await screen.findByRole("button", { name: /University Store/i }));
    await user.click(screen.getByRole("button", { name: /Validate on MariaDB/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/syntax error/i);
  });

  it("displays conflict alert (409) when deleting a dataset attached to an exam", async () => {
    const user = userEvent.setup();
    server.use(
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "examiner1", role: "examiner" } }),
      ),
      http.get(`${API}/examiner/datasets`, () =>
        HttpResponse.json({
          data: [{ ...sampleDataset, exams_count: 1 }],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        }),
      ),
      http.delete(`${API}/examiner/datasets/ds-1`, () =>
        HttpResponse.json(
          {
            message: "Dataset cannot be deleted while referenced by exams.",
            code: "dataset_in_use",
          },
          { status: 409 },
        ),
      ),
    );

    render(<DatasetsManager />);
    await user.click(await screen.findByRole("button", { name: /University Store/i }));
    await user.click(screen.getByRole("button", { name: /Delete Dataset/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(/referenced by exams/i);
  });

  it("creates a dataset, validates it on MariaDB, and displays introspected schema tables", async () => {
    const user = userEvent.setup();
    let createdPayload = null;
    const validatedDataset = {
      ...sampleDataset,
      id: "ds-new",
      name: "HR Payroll",
      validated_at: "2026-11-28T06:00:00Z",
      schema_summary: {
        tables: [
          {
            name: "employees",
            type: "BASE TABLE",
            row_count: 3,
            columns: [
              { name: "id", type: "int(11)", nullable: false, key: "PRI", default: null },
              { name: "salary", type: "decimal(10,2)", nullable: false, key: "", default: null },
            ],
            foreign_keys: [],
          },
        ],
      },
    };

    server.use(
      http.get(`${API}/user`, () =>
        HttpResponse.json({ data: { id: 2, username: "examiner1", role: "examiner" } }),
      ),
      http.get(`${API}/examiner/datasets`, () =>
        HttpResponse.json({ data: [], meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }),
      ),
      http.post(`${API}/examiner/datasets`, async ({ request }) => {
        createdPayload = await request.json();
        return HttpResponse.json({ data: { ...validatedDataset, validated_at: null, schema_summary: null } }, { status: 201 });
      }),
      http.post(`${API}/examiner/datasets/ds-new/validate`, () =>
        HttpResponse.json({ data: validatedDataset }),
      ),
    );

    render(<DatasetsManager />);
    expect(await screen.findByRole("heading", { name: /SQL Datasets/i })).toBeVisible();

    await user.click(screen.getByRole("button", { name: /New Dataset/i }));
    await user.type(screen.getByLabelText("Dataset Name"), "HR Payroll");
    await user.type(screen.getByLabelText("Schema DDL"), "CREATE TABLE employees (id INT PRIMARY KEY, salary DECIMAL(10,2));");
    await user.type(screen.getByLabelText("Seed SQL"), "INSERT INTO employees VALUES (1, 1000.00);");
    await user.click(screen.getByRole("button", { name: /Create Dataset/i }));

    await waitFor(() => expect(createdPayload?.name).toBe("HR Payroll"));
    await user.click(await screen.findByRole("button", { name: /Validate on MariaDB/i }));

    expect(await screen.findByText("employees")).toBeVisible();
    expect(screen.getByText(/decimal\(10,2\)/i)).toBeVisible();
  });
});
