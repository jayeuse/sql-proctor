"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api, extractApiError } from "../lib/api";
import ExaminerShell from "./ExaminerShell";

export default function ExamAttemptsList({ examId: examIdProp }) {
  const params = useParams();
  const router = useRouter();
  const routerRef = useRef(router);
  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const examId = examIdProp ?? params?.examId;

  const [currentUser, setCurrentUser] = useState(null);
  const [attempts, setAttempts] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [statusFilter, setStatusFilter] = useState("");
  const [gradingFilter, setGradingFilter] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const loadAttempts = useCallback(
    async (nextStatus, nextGrading, nextPage) => {
      if (!examId) {
        return;
      }
      const res = await api.get(`/examiner/exams/${examId}/attempts`, {
        params: {
          status: nextStatus || undefined,
          grading_status: nextGrading || undefined,
          page: nextPage,
        },
      });
      setAttempts(res.data?.data ?? []);
      setMeta(res.data?.meta ?? { current_page: 1, last_page: 1, total: 0 });
    },
    [examId],
  );

  useEffect(() => {
    let active = true;

    async function bootstrap() {
      if (!examId) {
        return;
      }
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
        await loadAttempts("", "", 1);
      } catch (err) {
        if (!active) {
          return;
        }
        const apiErr = extractApiError(err, "Failed to load exam attempts.");
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
  }, [examId, loadAttempts]);

  async function handleFilter(e) {
    e.preventDefault();
    setError("");
    try {
      setPage(1);
      await loadAttempts(statusFilter, gradingFilter, 1);
    } catch (err) {
      setError(extractApiError(err, "Failed to filter attempts.").message);
    }
  }

  return (
    <ExaminerShell title="Exam Attempts & Grading" activeSection="exams" currentUser={currentUser}>
      <div className="flex flex-wrap items-center gap-3 text-xs">
        <Link href={`/examiner/exams/${examId}`} className="font-medium text-indigo-600 hover:text-indigo-700">
          Back to Exam Builder
        </Link>
      </div>

      {error ? (
        <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          {error}
        </div>
      ) : null}

      <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
        <form onSubmit={handleFilter} className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <select
            aria-label="Attempt status filter"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
          >
            <option value="">All attempt states</option>
            <option value="in_progress">In progress</option>
            <option value="submitted">Submitted</option>
            <option value="auto_submitted">Auto submitted</option>
            <option value="force_closed">Force closed</option>
          </select>
          <select
            aria-label="Grading status filter"
            value={gradingFilter}
            onChange={(e) => setGradingFilter(e.target.value)}
            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
          >
            <option value="">All grading states</option>
            <option value="pending">Pending</option>
            <option value="needs_review">Needs review</option>
            <option value="auto_graded">Auto graded</option>
            <option value="finalized">Finalized</option>
          </select>
          <button
            type="submit"
            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            Apply Filters
          </button>
        </form>

        {loading ? (
          <p className="text-sm text-slate-600">Loading attempts...</p>
        ) : attempts.length === 0 ? (
          <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-600">
            No student attempts found for this exam.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
              <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                  <th className="px-3 py-2.5">Student</th>
                  <th className="px-3 py-2.5">Classroom</th>
                  <th className="px-3 py-2.5">Attempt</th>
                  <th className="px-3 py-2.5">Grading</th>
                  <th className="px-3 py-2.5">Score</th>
                  <th className="px-3 py-2.5 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {attempts.map((att) => (
                  <tr key={att.id}>
                    <td className="px-3 py-3 font-medium text-slate-900">{att.student_display_name}</td>
                    <td className="px-3 py-3 text-slate-600">{att.classroom?.name}</td>
                    <td className="px-3 py-3 capitalize text-slate-700">{att.status?.replace("_", " ")}</td>
                    <td className="px-3 py-3 capitalize text-slate-700">
                      {att.grading_status?.replace("_", " ")}
                    </td>
                    <td className="px-3 py-3 font-mono text-xs text-slate-900">
                      {att.final_score} / {att.max_score}
                    </td>
                    <td className="px-3 py-3 text-right">
                      <Link
                        href={`/examiner/attempts/${att.id}`}
                        className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                      >
                        Grade / Review
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
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
                  await loadAttempts(statusFilter, gradingFilter, next);
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
                  await loadAttempts(statusFilter, gradingFilter, next);
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
