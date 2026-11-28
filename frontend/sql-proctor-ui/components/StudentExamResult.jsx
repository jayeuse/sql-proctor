"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api, extractApiError } from "../lib/api";

function getExamOutcomeMeta(result) {
  if (result.passed === true) {
    return {
      label: "Passed",
      cardClass: "border-emerald-300 bg-emerald-50/70",
      badgeClass: "border border-emerald-200 bg-emerald-100 text-emerald-800",
      barClass: "bg-emerald-600",
    };
  }
  if (result.passed === false) {
    return {
      label: "Not Passed",
      cardClass: "border-rose-300 bg-rose-50/70",
      badgeClass: "border border-rose-200 bg-rose-100 text-rose-800",
      barClass: "bg-rose-600",
    };
  }
  return {
    label: result.grading_status === "needs_review" ? "Pending Manual Review" : "Grading In Progress",
    cardClass: "border-amber-300 bg-amber-50/70",
    badgeClass: "border border-amber-200 bg-amber-100 text-amber-900",
    barClass: "bg-amber-500",
  };
}

function getQuestionOutcomeMeta(q) {
  const score = Number(q.final_score ?? 0);
  const max = Number(q.max_points ?? 0);

  if (q.grading_status === "needs_review") {
    return {
      label: "Needs Review",
      cardClass: "border-slate-200 border-l-4 border-l-amber-500 bg-white",
      badgeClass: "border border-amber-200 bg-amber-100 text-amber-900",
      scoreClass: "border border-amber-200 bg-amber-50 text-amber-900",
    };
  }

  if (q.grading_status === "unanswered") {
    return {
      label: "Unanswered",
      cardClass: "border-slate-200 border-l-4 border-l-rose-500 bg-white",
      badgeClass: "border border-rose-200 bg-rose-100 text-rose-800",
      scoreClass: "border border-rose-200 bg-rose-50 text-rose-800",
    };
  }

  if (max > 0 && score >= max) {
    return {
      label: "Full Credit",
      cardClass: "border-slate-200 border-l-4 border-l-emerald-500 bg-white",
      badgeClass: "border border-emerald-200 bg-emerald-100 text-emerald-800",
      scoreClass: "border border-emerald-200 bg-emerald-50 text-emerald-800",
    };
  }

  if (score > 0 && score < max) {
    return {
      label: "Partial Credit",
      cardClass: "border-slate-200 border-l-4 border-l-amber-500 bg-white",
      badgeClass: "border border-amber-200 bg-amber-100 text-amber-900",
      scoreClass: "border border-amber-200 bg-amber-50 text-amber-900",
    };
  }

  return {
    label: "Failed",
    cardClass: "border-slate-200 border-l-4 border-l-rose-500 bg-white",
    badgeClass: "border border-rose-200 bg-rose-100 text-rose-800",
    scoreClass: "border border-rose-200 bg-rose-50 text-rose-800",
  };
}

function getHiddenSummaryBadgeClass(summary) {
  if (summary.passed >= summary.total) {
    return "border border-emerald-200 bg-emerald-50 text-emerald-800";
  }
  if (summary.passed > 0) {
    return "border border-amber-200 bg-amber-50 text-amber-900";
  }
  return "border border-rose-200 bg-rose-50 text-rose-800";
}

export default function StudentExamResult({ attemptId: attemptIdProp }) {
  const params = useParams();
  const router = useRouter();
  const routerRef = useRef(router);
  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const attemptId = attemptIdProp ?? params?.attemptId;

  const [result, setResult] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;

    async function load() {
      if (!attemptId) {
        return;
      }
      try {
        const res = await api.get(`/student/attempts/${attemptId}/result`);
        if (active) {
          setResult(res.data?.data ?? null);
        }
      } catch (err) {
        if (!active) {
          return;
        }
        const apiErr = extractApiError(err, "Failed to load exam result.");
        if (apiErr.status === 401) {
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

    load();

    return () => {
      active = false;
    };
  }, [attemptId]);

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-10">
      <main className="mx-auto max-w-4xl space-y-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">
              SQL-Proctor Exam Result
            </p>
            <h1 className="text-2xl font-semibold text-slate-900">{result?.title ?? "Exam Result"}</h1>
          </div>
          <Link
            href="/student"
            className="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            Back to Student Console
          </Link>
        </div>

        {error ? (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {error}
          </div>
        ) : null}

        {loading ? (
          <p role="status" className="text-sm text-slate-600">
            Loading exam result...
          </p>
        ) : result ? (
          !result.released ? (
            <section className="space-y-2 rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
              <p className="text-base font-medium text-slate-900">
                {result.message ?? "Submitted — awaiting examiner result release."}
              </p>
              <p className="text-xs text-slate-600">
                Status: <span className="capitalize">{result.status?.replace("_", " ")}</span> · Grading:{" "}
                <span className="capitalize">{result.grading_status?.replace("_", " ")}</span>
              </p>
            </section>
          ) : (
            (() => {
              const examMeta = getExamOutcomeMeta(result);
              const pct = Math.min(100, Math.max(0, Number(result.percentage ?? 0)));
              return (
                <div className="space-y-6">
                  <section className={`space-y-4 rounded-2xl border p-6 shadow-xs ${examMeta.cardClass}`}>
                    <div className="flex flex-wrap items-center justify-between gap-4">
                      <div className="space-y-1">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Final Score</p>
                        <p className="font-mono text-2xl font-semibold text-slate-900">
                          {result.final_score} / {result.max_score} ({result.percentage}%)
                        </p>
                        <p className="text-xs text-slate-600">
                          Passing threshold: {result.passing_score_percent}%
                        </p>
                      </div>
                      <span className={`rounded-full px-3.5 py-1 text-sm font-semibold ${examMeta.badgeClass}`}>
                        {examMeta.label}
                      </span>
                    </div>

                    <div className="h-2.5 w-full overflow-hidden rounded-full bg-white/80 ring-1 ring-slate-200">
                      <div
                        className={`h-full rounded-full transition-all ${examMeta.barClass}`}
                        style={{ width: `${pct}%` }}
                      />
                    </div>
                  </section>

                  <div className="space-y-4">
                    {(result.questions ?? []).map((q) => {
                      const qMeta = getQuestionOutcomeMeta(q);
                      return (
                        <article
                          key={q.question_id}
                          className={`space-y-3 rounded-xl border p-5 shadow-xs ${qMeta.cardClass}`}
                        >
                          <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-semibold text-slate-900">
                              Q{q.position}. {q.title}
                            </h2>
                            <div className="flex flex-wrap items-center gap-2">
                              <span
                                className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${qMeta.badgeClass}`}
                              >
                                {qMeta.label}
                              </span>
                              <span
                                className={`rounded px-2.5 py-1 font-mono text-xs font-semibold ${qMeta.scoreClass}`}
                              >
                                {q.final_score} of {q.max_points} pts
                              </span>
                            </div>
                          </div>

                          {q.submitted_sql ? (
                            <pre className="overflow-x-auto rounded-lg border border-slate-200 bg-slate-50 p-3 font-mono text-xs text-slate-800">
                              {q.submitted_sql}
                            </pre>
                          ) : q.submitted_text ? (
                            <p className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800">
                              {q.submitted_text}
                            </p>
                          ) : Array.isArray(q.selected_options) && q.selected_options.length > 0 ? (
                            <div className="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800">
                              <span className="font-medium text-slate-600">Selected:</span>
                              {q.selected_options.map((opt) => (
                                <span
                                  key={opt.id}
                                  className="rounded border border-slate-300 bg-white px-2 py-0.5 font-medium text-slate-900"
                                >
                                  {opt.label}
                                </span>
                              ))}
                            </div>
                          ) : null}

                          {(q.visible_test_results ?? []).length > 0 ? (
                            <ul className="space-y-1.5 text-xs">
                              {q.visible_test_results.map((tc, idx) => (
                                <li
                                  key={`${tc.name}-${idx}`}
                                  className={`space-y-0.5 rounded border px-3 py-2 ${
                                    tc.passed
                                      ? "border-emerald-200 bg-emerald-50/60"
                                      : "border-rose-200 bg-rose-50/60"
                                  }`}
                                >
                                  <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium text-slate-900">{tc.name}</span>
                                    <span
                                      className={`rounded px-2 py-0.5 font-mono font-semibold ${
                                        tc.passed
                                          ? "bg-emerald-100 text-emerald-800"
                                          : "bg-rose-100 text-rose-800"
                                      }`}
                                    >
                                      {tc.passed ? "Pass" : "Fail"} · {tc.points_earned}/{tc.weight_points} pts
                                    </span>
                                  </div>
                                  {tc.message ? (
                                    <p className={tc.passed ? "text-emerald-800" : "text-rose-800"}>{tc.message}</p>
                                  ) : null}
                                </li>
                              ))}
                            </ul>
                          ) : null}

                          {q.hidden_tests_summary && q.hidden_tests_summary.total > 0 ? (
                            <div>
                              <span
                                className={`inline-flex rounded px-2.5 py-1 text-xs font-medium ${getHiddenSummaryBadgeClass(
                                  q.hidden_tests_summary,
                                )}`}
                              >
                                Hidden tests: {q.hidden_tests_summary.passed} / {q.hidden_tests_summary.total} passed
                              </span>
                            </div>
                          ) : null}

                          {q.examiner_feedback ? (
                            <div className="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-950">
                              <span className="font-semibold">Examiner feedback: </span>
                              {q.examiner_feedback}
                            </div>
                          ) : null}
                        </article>
                      );
                    })}
                  </div>
                </div>
              );
            })()
          )
        ) : null}
      </main>
    </div>
  );
}
