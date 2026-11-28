"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { api, ensureCsrfCookie, extractApiError } from "../lib/api";
import ExaminerShell from "./ExaminerShell";
import SchemaSummaryPanel from "./SchemaSummaryPanel";
import SqlEditor from "./SqlEditor";

const EMPTY_FORM = {
  id: null,
  name: "",
  description: "",
  schema_sql: "",
  seed_sql: "",
  is_shared: false,
  validated_at: null,
  schema_summary: null,
  exams_count: 0,
};

export default function DatasetsManager() {
  const router = useRouter();
  const routerRef = useRef(router);
  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const [currentUser, setCurrentUser] = useState(null);
  const [datasets, setDatasets] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [scope, setScope] = useState("all");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [validating, setValidating] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [form, setForm] = useState(EMPTY_FORM);

  const loadDatasets = useCallback(async (nextScope, nextSearch, nextPage) => {
    const res = await api.get("/examiner/datasets", {
      params: {
        scope: nextScope,
        search: nextSearch || undefined,
        page: nextPage,
      },
    });
    setDatasets(res.data?.data ?? []);
    setMeta(res.data?.meta ?? { current_page: 1, last_page: 1, total: 0 });
  }, []);

  useEffect(() => {
    let active = true;

    async function bootstrap() {
      try {
        const userRes = await api.get("/user");
        const user = userRes.data?.data;
        if (!user || !["examiner", "admin"].includes(user.role)) {
          routerRef.current.push("/");
          return;
        }
        if (!active) {
          return;
        }
        setCurrentUser(user);
        await loadDatasets("all", "", 1);
      } catch (err) {
        if (!active) {
          return;
        }
        const apiErr = extractApiError(err, "Failed to load datasets.");
        if (apiErr.status === 401 || apiErr.status === 403) {
          routerRef.current.push("/");
          return;
        }
        setError(apiErr.message);
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    }

    bootstrap();

    return () => {
      active = false;
    };
  }, [loadDatasets]);

  function selectDataset(dataset) {
    setError("");
    setNotice("");
    setForm({
      id: dataset.id,
      name: dataset.name ?? "",
      description: dataset.description ?? "",
      schema_sql: dataset.schema_sql ?? "",
      seed_sql: dataset.seed_sql ?? "",
      is_shared: Boolean(dataset.is_shared),
      validated_at: dataset.validated_at ?? null,
      schema_summary: dataset.schema_summary ?? null,
      exams_count: dataset.exams_count ?? 0,
    });
  }

  function resetToNew() {
    setError("");
    setNotice("");
    setForm(EMPTY_FORM);
  }

  async function refreshList(targetPage = page) {
    await loadDatasets(scope, search, targetPage);
  }

  async function handleFilterSubmit(e) {
    e.preventDefault();
    setError("");
    try {
      setPage(1);
      await loadDatasets(scope, search, 1);
    } catch (err) {
      setError(extractApiError(err, "Failed to filter datasets.").message);
    }
  }

  async function handleSave(e) {
    e.preventDefault();
    setError("");
    setNotice("");
    setSaving(true);

    try {
      await ensureCsrfCookie();
      const payload = {
        name: form.name,
        description: form.description || null,
        schema_sql: form.schema_sql,
        seed_sql: form.seed_sql || null,
      };
      if (currentUser?.role === "admin") {
        payload.is_shared = form.is_shared;
      }

      const res = form.id
        ? await api.patch(`/examiner/datasets/${form.id}`, payload)
        : await api.post("/examiner/datasets", payload);

      const saved = res.data?.data;
      if (saved) {
        selectDataset(saved);
      }
      setNotice(form.id ? "Dataset saved. Re-validate schema before publishing." : "Dataset created.");
      await refreshList(1);
      setPage(1);
    } catch (err) {
      setError(extractApiError(err, "Failed to save dataset.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleValidate() {
    if (!form.id) {
      return;
    }
    setError("");
    setNotice("");
    setValidating(true);

    try {
      await ensureCsrfCookie();
      const res = await api.post(`/examiner/datasets/${form.id}/validate`);
      const updated = res.data?.data;
      if (updated) {
        selectDataset(updated);
      }
      setNotice("Schema and seed SQL validated cleanly on MariaDB 11.8.");
      await refreshList(page);
    } catch (err) {
      setError(extractApiError(err, "Dataset validation failed.").message);
    } finally {
      setValidating(false);
    }
  }

  async function handleDelete() {
    if (!form.id) {
      return;
    }
    setError("");
    setNotice("");
    setDeleting(true);

    try {
      await ensureCsrfCookie();
      await api.delete(`/examiner/datasets/${form.id}`);
      resetToNew();
      setNotice("Dataset deleted.");
      await refreshList(1);
      setPage(1);
    } catch (err) {
      setError(extractApiError(err, "Failed to delete dataset.").message);
    } finally {
      setDeleting(false);
    }
  }

  return (
    <ExaminerShell title="SQL Datasets" activeSection="datasets" currentUser={currentUser}>
      {error ? (
        <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          {error}
        </div>
      ) : null}

      {notice ? (
        <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          {notice}
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-base font-semibold text-slate-900">Dataset Library</h2>
            <button
              type="button"
              onClick={resetToNew}
              className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
            >
              New Dataset
            </button>
          </div>

          <form onSubmit={handleFilterSubmit} className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <label className="block sm:col-span-1">
              <span className="sr-only">Dataset scope</span>
              <select
                aria-label="Dataset scope"
                value={scope}
                onChange={(e) => setScope(e.target.value)}
                className="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:outline-none"
              >
                <option value="all">All</option>
                <option value="mine">Mine</option>
                <option value="shared">Shared</option>
              </select>
            </label>
            <label className="block sm:col-span-2">
              <span className="sr-only">Search datasets</span>
              <div className="flex gap-2">
                <input
                  aria-label="Search datasets"
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search by name..."
                  className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:outline-none"
                />
                <button
                  type="submit"
                  className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >
                  Filter
                </button>
              </div>
            </label>
          </form>

          {loading ? (
            <p className="text-sm text-slate-600">Loading datasets...</p>
          ) : datasets.length === 0 ? (
            <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-600">
              No SQL datasets found. Create a dataset to get started.
            </p>
          ) : (
            <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200">
              {datasets.map((ds) => {
                const isSelected = form.id === ds.id;
                return (
                  <li key={ds.id}>
                    <button
                      type="button"
                      onClick={() => selectDataset(ds)}
                      className={`flex w-full items-start justify-between gap-3 px-4 py-3 text-left transition-colors ${
                        isSelected ? "bg-indigo-50" : "bg-white hover:bg-slate-50"
                      }`}
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-slate-900">{ds.name}</p>
                        {ds.description ? (
                          <p className="mt-0.5 line-clamp-1 text-xs text-slate-600">{ds.description}</p>
                        ) : null}
                      </div>
                      <span
                        className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                          ds.validated_at
                            ? "bg-emerald-100 text-emerald-800"
                            : "bg-amber-100 text-amber-800"
                        }`}
                      >
                        {ds.validated_at ? "Validated" : "Draft"}
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}

          {meta.last_page > 1 ? (
            <div className="flex items-center justify-between text-xs text-slate-600">
              <span>
                Page {meta.current_page} of {meta.last_page}
              </span>
              <div className="flex gap-2">
                <button
                  type="button"
                  disabled={page <= 1}
                  onClick={async () => {
                    const next = page - 1;
                    setPage(next);
                    await loadDatasets(scope, search, next);
                  }}
                  className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-50"
                >
                  Previous
                </button>
                <button
                  type="button"
                  disabled={page >= meta.last_page}
                  onClick={async () => {
                    const next = page + 1;
                    setPage(next);
                    await loadDatasets(scope, search, next);
                  }}
                  className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            </div>
          ) : null}
        </section>

        <section className="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-7">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-base font-semibold text-slate-900">
              {form.id ? `Edit Dataset: ${form.name}` : "Create SQL Dataset"}
            </h2>
            {form.id ? (
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  disabled={validating}
                  onClick={handleValidate}
                  className="rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 disabled:opacity-60"
                >
                  {validating ? "Validating..." : "Validate on MariaDB"}
                </button>
                <button
                  type="button"
                  disabled={deleting}
                  onClick={handleDelete}
                  className="rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 disabled:opacity-60"
                >
                  {deleting ? "Deleting..." : "Delete Dataset"}
                </button>
              </div>
            ) : null}
          </div>

          <form onSubmit={handleSave} className="space-y-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label htmlFor="dataset-name" className="block text-sm font-medium text-slate-700">
                  Dataset Name
                </label>
                <input
                  id="dataset-name"
                  type="text"
                  value={form.name}
                  onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
                  placeholder="e.g. University Store"
                  className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
                />
              </div>
              <div>
                <label htmlFor="dataset-description" className="block text-sm font-medium text-slate-700">
                  Description
                </label>
                <input
                  id="dataset-description"
                  type="text"
                  value={form.description}
                  onChange={(e) => setForm((prev) => ({ ...prev, description: e.target.value }))}
                  placeholder="Optional overview"
                  className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
                />
              </div>
            </div>

            {currentUser?.role === "admin" ? (
              <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={form.is_shared}
                  onChange={(e) => setForm((prev) => ({ ...prev, is_shared: e.target.checked }))}
                  className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                />
                Shared with all examiners
              </label>
            ) : null}

            <SqlEditor
              id="dataset-schema-sql"
              label="Schema DDL"
              value={form.schema_sql}
              onChange={(next) => setForm((prev) => ({ ...prev, schema_sql: next }))}
              height={200}
              placeholder="CREATE TABLE students (id INT PRIMARY KEY, name VARCHAR(80));"
            />

            <SqlEditor
              id="dataset-seed-sql"
              label="Seed SQL"
              value={form.seed_sql}
              onChange={(next) => setForm((prev) => ({ ...prev, seed_sql: next }))}
              height={160}
              placeholder="INSERT INTO students VALUES (1, 'Ada Lovelace');"
            />

            <div className="flex flex-wrap items-center gap-3">
              <button
                type="submit"
                disabled={saving}
                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
              >
                {saving ? "Saving..." : form.id ? "Save Dataset" : "Create Dataset"}
              </button>
            </div>
          </form>

          <div className="border-t border-slate-200 pt-4">
            <h3 className="mb-2 text-sm font-semibold text-slate-900">Introspected MariaDB Schema</h3>
            <SchemaSummaryPanel
              schemaSummary={form.schema_summary}
              emptyText="Run Validate on MariaDB to inspect tables, columns, keys, and seed row counts."
            />
          </div>
        </section>
      </div>
    </ExaminerShell>
  );
}
