"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { api, ensureCsrfCookie, extractApiError } from "../lib/api";
import ExaminerShell from "./ExaminerShell";

const STATUS_STYLES = {
  draft: "bg-slate-100 text-slate-800",
  published: "bg-emerald-100 text-emerald-800",
  unpublished: "bg-amber-100 text-amber-800",
  archived: "bg-rose-100 text-rose-800",
};

export default function ExamsManager() {
  const router = useRouter();
  const routerRef = useRef(router);
  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const [currentUser, setCurrentUser] = useState(null);
  const [exams, setExams] = useState([]);
  const [datasets, setDatasets] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [statusFilter, setStatusFilter] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [showCreate, setShowCreate] = useState(false);
  const [creating, setCreating] = useState(false);
  const [busyId, setBusyId] = useState(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [createForm, setCreateForm] = useState({
    title: "",
    description: "",
    dataset_id: "",
    duration_minutes: 60,
    passing_score_percent: 60,
  });

  const loadExams = useCallback(async (statusVal, searchVal, pageVal) => {
    const res = await api.get("/examiner/exams", {
      params: {
        status: statusVal || undefined,
        search: searchVal || undefined,
        page: pageVal,
      },
    });
    setExams(res.data?.data ?? []);
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

        const [, dsRes] = await Promise.all([
          loadExams("", "", 1),
          api.get("/examiner/datasets", { params: { scope: "all", per_page: 50 } }),
        ]);
        if (active) {
          setDatasets(dsRes.data?.data ?? []);
        }
      } catch (err) {
        if (!active) {
          return;
        }
        const apiErr = extractApiError(err, "Failed to load exams.");
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
  }, [loadExams]);

  async function handleFilterSubmit(e) {
    e.preventDefault();
    setError("");
    try {
      setPage(1);
      await loadExams(statusFilter, search, 1);
    } catch (err) {
      setError(extractApiError(err, "Failed to filter exams.").message);
    }
  }

  async function handleCreate(e) {
    e.preventDefault();
    setError("");
    setNotice("");
    setCreating(true);

    try {
      await ensureCsrfCookie();
      const res = await api.post("/examiner/exams", {
        title: createForm.title,
        description: createForm.description || null,
        dataset_id: createForm.dataset_id || null,
        duration_minutes: Number(createForm.duration_minutes) || 60,
        passing_score_percent: Number(createForm.passing_score_percent) || 60,
      });
      const created = res.data?.data;
      if (created?.id) {
        router.push(`/examiner/exams/${created.id}`);
        return;
      }
      setShowCreate(false);
      await loadExams(statusFilter, search, 1);
    } catch (err) {
      setError(extractApiError(err, "Failed to create exam.").message);
    } finally {
      setCreating(false);
    }
  }

  async function handleClone(examId) {
    setError("");
    setNotice("");
    setBusyId(examId);
    try {
      await ensureCsrfCookie();
      const res = await api.post(`/examiner/exams/${examId}/clone`);
      const cloned = res.data?.data;
      if (cloned?.id) {
        router.push(`/examiner/exams/${cloned.id}`);
        return;
      }
      await loadExams(statusFilter, search, page);
    } catch (err) {
      setError(extractApiError(err, "Failed to clone exam.").message);
    } finally {
      setBusyId(null);
    }
  }

  async function handleDelete(examId) {
    setError("");
    setNotice("");
    setBusyId(examId);
    try {
      await ensureCsrfCookie();
      await api.delete(`/examiner/exams/${examId}`);
      setNotice("Exam deleted.");
      await loadExams(statusFilter, search, page);
    } catch (err) {
      setError(extractApiError(err, "Failed to delete exam.").message);
    } finally {
      setBusyId(null);
    }
  }

  return (
    <ExaminerShell title="Exams" activeSection="exams" currentUser={currentUser}>
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

      <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold text-slate-900">Exam Blueprints</h2>
            <p className="text-xs text-slate-600">
              Author questions, attach MariaDB datasets, publish immutable versions, and grade student attempts.
            </p>
          </div>
          <button
            type="button"
            onClick={() => setShowCreate((prev) => !prev)}
            className="rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
          >
            {showCreate ? "Close Form" : "New Exam"}
          </button>
        </div>

        {showCreate ? (
          <form onSubmit={handleCreate} className="space-y-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label htmlFor="exam-title" className="block text-sm font-medium text-slate-700">
                  Exam Title
                </label>
                <input
                  id="exam-title"
                  type="text"
                  value={createForm.title}
                  onChange={(e) => setCreateForm((p) => ({ ...p, title: e.target.value }))}
                  placeholder="e.g. Relational SQL Midterm"
                  className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
                />
              </div>
              <div>
                <label htmlFor="exam-dataset" className="block text-sm font-medium text-slate-700">
                  SQL Dataset
                </label>
                <select
                  id="exam-dataset"
                  value={createForm.dataset_id}
                  onChange={(e) => setCreateForm((p) => ({ ...p, dataset_id: e.target.value }))}
                  className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
                >
                  <option value="">None attached yet</option>
                  {datasets.map((ds) => (
                    <option key={ds.id} value={ds.id}>
                      {ds.name} {ds.validated_at ? "(Validated)" : "(Unvalidated)"}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label htmlFor="exam-duration" className="block text-sm font-medium text-slate-700">
                  Duration (minutes)
                </label>
                <input
                  id="exam-duration"
                  type="number"
                  min={1}
                  max={480}
                  value={createForm.duration_minutes}
                  onChange={(e) => setCreateForm((p) => ({ ...p, duration_minutes: e.target.value }))}
                  className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
                />
              </div>
              <div>
                <label htmlFor="exam-passing" className="block text-sm font-medium text-slate-700">
                  Passing Score (%)
                </label>
                <input
                  id="exam-passing"
                  type="number"
                  min={0}
                  max={100}
                  value={createForm.passing_score_percent}
                  onChange={(e) => setCreateForm((p) => ({ ...p, passing_score_percent: e.target.value }))}
                  className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
                />
              </div>
            </div>
            <div>
              <label htmlFor="exam-description" className="block text-sm font-medium text-slate-700">
                Description
              </label>
              <textarea
                id="exam-description"
                rows={2}
                value={createForm.description}
                onChange={(e) => setCreateForm((p) => ({ ...p, description: e.target.value }))}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
              />
            </div>
            <button
              type="submit"
              disabled={creating}
              className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60"
            >
              {creating ? "Creating..." : "Create Exam"}
            </button>
          </form>
        ) : null}

        <form onSubmit={handleFilterSubmit} className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <select
            aria-label="Exam status filter"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
          >
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="unpublished">Unpublished</option>
            <option value="archived">Archived</option>
          </select>
          <input
            aria-label="Search exams"
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search exams by title..."
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 sm:col-span-2"
          />
          <button
            type="submit"
            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            Apply Filters
          </button>
        </form>

        {loading ? (
          <p className="text-sm text-slate-600">Loading exams...</p>
        ) : exams.length === 0 ? (
          <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-600">
            No exams match the selected filters.
          </p>
        ) : (
          <div className="space-y-3">
            {exams.map((exam) => (
              <div
                key={exam.id}
                className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 p-4"
              >
                <div className="min-w-0 space-y-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <h3 className="text-base font-semibold text-slate-900">{exam.title}</h3>
                    <span
                      className={`rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${
                        STATUS_STYLES[exam.status] ?? "bg-slate-100 text-slate-800"
                      }`}
                    >
                      {exam.status}
                    </span>
                    {exam.current_version_number > 0 ? (
                      <span className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700">
                        v{exam.current_version_number}
                      </span>
                    ) : null}
                  </div>
                  <p className="text-xs text-slate-600">
                    {exam.questions_count ?? 0} questions · {exam.total_points} pts · {exam.duration_minutes} min ·{" "}
                    {exam.attempts_count ?? 0} attempts
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  <Link
                    href={`/examiner/exams/${exam.id}`}
                    className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                  >
                    Open Builder
                  </Link>
                  <Link
                    href={`/examiner/exams/${exam.id}/attempts`}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                  >
                    Attempts
                  </Link>
                  <button
                    type="button"
                    disabled={busyId === exam.id}
                    onClick={() => handleClone(exam.id)}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  >
                    Clone
                  </button>
                  {exam.is_editable && (exam.attempts_count ?? 0) === 0 ? (
                    <button
                      type="button"
                      disabled={busyId === exam.id}
                      onClick={() => handleDelete(exam.id)}
                      className="rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-100 disabled:opacity-50"
                    >
                      Delete
                    </button>
                  ) : null}
                </div>
              </div>
            ))}
          </div>
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
                  await loadExams(statusFilter, search, next);
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
                  await loadExams(statusFilter, search, next);
                }}
                className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-50"
              >
                Next
              </button>
            </div>
          </div>
        ) : null}
      </section>
    </ExaminerShell>
  );
}
