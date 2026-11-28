"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api, ensureCsrfCookie, extractApiError } from "../lib/api";
import ExaminerShell from "./ExaminerShell";
import SqlEditor from "./SqlEditor";

function buildDraftMap(items) {
  const map = {};
  for (const item of items ?? []) {
    if (!item.answer) {
      continue;
    }
    const ans = item.answer;
    const rawScore = ans.manual_score ?? (ans.grading_status === "needs_review" ? "" : ans.final_score ?? "0");
    map[ans.id] = {
      manual_score: rawScore === "" ? "" : String(Number(rawScore)),
      examiner_feedback: ans.examiner_feedback ?? "",
      override_reason: ans.override_reason ?? "",
    };
  }
  return map;
}

export default function AttemptGradingView({ attemptId: attemptIdProp }) {
  const params = useParams();
  const router = useRouter();
  const routerRef = useRef(router);
  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const attemptId = attemptIdProp ?? params?.attemptId;

  const [currentUser, setCurrentUser] = useState(null);
  const [detail, setDetail] = useState(null);
  const [drafts, setDrafts] = useState({});
  const [loading, setLoading] = useState(true);
  const [savingId, setSavingId] = useState(null);
  const [finalizing, setFinalizing] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const refreshDetail = useCallback(async () => {
    if (!attemptId) {
      return;
    }
    const res = await api.get(`/examiner/attempts/${attemptId}`);
    const loaded = res.data?.data;
    if (loaded) {
      setDetail(loaded);
      setDrafts(buildDraftMap(loaded.items));
    }
  }, [attemptId]);

  useEffect(() => {
    let active = true;

    async function bootstrap() {
      if (!attemptId) {
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
        const res = await api.get(`/examiner/attempts/${attemptId}`);
        if (!active) {
          return;
        }
        const loaded = res.data?.data;
        if (loaded) {
          setDetail(loaded);
          setDrafts(buildDraftMap(loaded.items));
        }
      } catch (err) {
        if (!active) {
          return;
        }
        const apiErr = extractApiError(err, "Failed to load attempt review.");
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
  }, [attemptId]);

  async function handleSaveGrade(item) {
    const answer = item.answer;
    if (!answer) {
      return;
    }
    setError("");
    setNotice("");

    const draft = drafts[answer.id] ?? {
      manual_score: "0",
      examiner_feedback: "",
      override_reason: "",
    };

    if (item.question.grading_mode === "auto" && !String(draft.override_reason ?? "").trim()) {
      setError("An override reason is required when overriding an auto-graded question.");
      return;
    }

    setSavingId(answer.id);
    try {
      await ensureCsrfCookie();
      await api.patch(`/examiner/attempts/${attemptId}/answers/${answer.id}/grade`, {
        manual_score: Number(draft.manual_score) || 0,
        examiner_feedback: draft.examiner_feedback || null,
        override_reason: draft.override_reason || null,
      });
      await refreshDetail();
      setNotice(`Saved grade for ${item.question.title}.`);
    } catch (err) {
      setError(extractApiError(err, "Failed to save answer grade.").message);
    } finally {
      setSavingId(null);
    }
  }

  async function handleFinalize() {
    setError("");
    setNotice("");
    setFinalizing(true);
    try {
      await ensureCsrfCookie();
      const res = await api.post(`/examiner/attempts/${attemptId}/finalize`);
      const updated = res.data?.data;
      if (updated) {
        setDetail(updated);
        setDrafts(buildDraftMap(updated.items));
      } else {
        await refreshDetail();
      }
      setNotice("Grades locked and ready for result release.");
    } catch (err) {
      setError(extractApiError(err, "Failed to finalize attempt.").message);
    } finally {
      setFinalizing(false);
    }
  }

  return (
    <ExaminerShell
      title={detail ? `Grading: ${detail.student_display_name}` : "Grading Review"}
      activeSection="exams"
      currentUser={currentUser}
    >
      {detail?.exam_id ? (
        <div className="flex flex-wrap items-center gap-3 text-xs">
          <Link
            href={`/examiner/exams/${detail.exam_id}/attempts`}
            className="font-medium text-indigo-600 hover:text-indigo-700"
          >
            Back to Attempts
          </Link>
        </div>
      ) : null}

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

      {loading ? (
        <p className="text-sm text-slate-600">Loading attempt details...</p>
      ) : detail ? (
        <div className="space-y-6">
          <section className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
            <div className="space-y-1">
              <h2 className="text-base font-semibold text-slate-900">{detail.exam_title}</h2>
              <p className="text-xs text-slate-600">
                Classroom: {detail.classroom?.name} · Status: {detail.status} · Grading:{" "}
                <span className="font-semibold capitalize">{detail.grading_status?.replace("_", " ")}</span>
              </p>
              <p className="font-mono text-sm font-semibold text-slate-900">
                Total Score: {detail.final_score} / {detail.max_score}
                {detail.passed !== null ? (
                  <span
                    className={`ml-2 rounded-full px-2 py-0.5 font-sans text-xs font-medium ${
                      detail.passed ? "bg-emerald-100 text-emerald-800" : "bg-rose-100 text-rose-800"
                    }`}
                  >
                    {detail.passed ? "Passed" : "Not Passed"}
                  </span>
                ) : null}
              </p>
            </div>
            <button
              type="button"
              disabled={finalizing}
              onClick={handleFinalize}
              className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
            >
              {finalizing ? "Finalizing..." : "Finalize Attempt"}
            </button>
          </section>

          <div className="space-y-5">
            {(detail.items ?? []).map((item, idx) => {
              const q = item.question;
              const ans = item.answer;
              const draft = ans
                ? drafts[ans.id] ?? { manual_score: "0", examiner_feedback: "", override_reason: "" }
                : null;
              const requiresOverrideReason = q.grading_mode === "auto";

              return (
                <article key={q.id} className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <h3 className="text-base font-semibold text-slate-900">{q.title}</h3>
                      <p className="text-xs text-slate-600">
                        Q{idx + 1} · {q.question_type} · Mode: {q.grading_mode} · Max: {q.points} pts
                      </p>
                    </div>
                    <span className="rounded bg-slate-100 px-2.5 py-1 font-mono text-xs font-semibold text-slate-800">
                      Earned: {ans ? ans.final_score : "0.00"} / {q.points}
                    </span>
                  </div>

                  <p className="text-sm text-slate-700">{q.prompt}</p>

                  {!ans ? (
                    <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-xs text-slate-600">
                      Student left this question unanswered.
                    </p>
                  ) : (
                    <div className="space-y-4">
                      {q.question_type.startsWith("sql_") ? (
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                          <SqlEditor
                            label="Student Submitted SQL"
                            value={ans.submitted_sql ?? ""}
                            readOnly
                            height={130}
                          />
                          <SqlEditor
                            label="Reference SQL"
                            value={q.reference_sql ?? ""}
                            readOnly
                            height={130}
                          />
                        </div>
                      ) : null}

                      {q.question_type === "short_answer" ? (
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800">
                          {ans.submitted_text || "(blank response)"}
                        </div>
                      ) : null}

                      {q.question_type === "mcq" ? (
                        <ul className="space-y-1 text-xs">
                          {(q.options ?? []).map((opt) => {
                            const picked = (ans.selected_option_ids ?? []).includes(opt.id);
                            return (
                              <li
                                key={opt.id}
                                className={`rounded border px-3 py-1.5 ${
                                  picked ? "border-indigo-400 bg-indigo-50" : "border-slate-200"
                                }`}
                              >
                                {opt.label} {picked ? "(Selected)" : ""} {opt.is_correct ? "[Correct]" : ""}
                              </li>
                            );
                          })}
                        </ul>
                      ) : null}

                      {(ans.test_results ?? []).length > 0 ? (
                        <div className="overflow-x-auto rounded-lg border border-slate-200">
                          <table className="min-w-full divide-y divide-slate-200 text-left text-xs">
                            <thead className="bg-slate-50 text-slate-600">
                              <tr>
                                <th className="px-3 py-2">Test Case</th>
                                <th className="px-3 py-2">Visibility</th>
                                <th className="px-3 py-2">Outcome</th>
                                <th className="px-3 py-2">Points</th>
                                <th className="px-3 py-2">Details</th>
                              </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200">
                              {ans.test_results.map((tc) => (
                                <tr key={tc.test_case_id ?? tc.name}>
                                  <td className="px-3 py-2 font-medium text-slate-900">{tc.name}</td>
                                  <td className="px-3 py-2">{tc.is_hidden ? "Hidden" : "Visible"}</td>
                                  <td className="px-3 py-2">
                                    <span
                                      className={`rounded px-1.5 py-0.5 font-semibold ${
                                        tc.passed ? "bg-emerald-100 text-emerald-800" : "bg-rose-100 text-rose-800"
                                      }`}
                                    >
                                      {tc.passed ? "Pass" : "Fail"}
                                    </span>
                                  </td>
                                  <td className="px-3 py-2 font-mono">
                                    {tc.points_earned} / {tc.weight_points}
                                  </td>
                                  <td className="px-3 py-2 text-slate-600">{tc.message}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      ) : null}

                      <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-3">
                        <div>
                          <label htmlFor={`score-${ans.id}`} className="block text-xs font-medium text-slate-700">
                            Manual Score (max {q.points})
                          </label>
                          <input
                            id={`score-${ans.id}`}
                            type="number"
                            step="any"
                            min="0"
                            max={Number(q.points)}
                            value={draft.manual_score}
                            onChange={(e) => {
                              const nextValue = e.target.value;
                              setDrafts((prev) => ({
                                ...prev,
                                [ans.id]: { ...prev[ans.id], manual_score: nextValue },
                              }));
                            }}
                            className="mt-1 w-full rounded border border-slate-300 bg-white px-2.5 py-1.5 text-sm"
                          />
                        </div>
                        <div className="sm:col-span-2">
                          <label htmlFor={`feedback-${ans.id}`} className="block text-xs font-medium text-slate-700">
                            Examiner Feedback
                          </label>
                          <input
                            id={`feedback-${ans.id}`}
                            type="text"
                            value={draft.examiner_feedback}
                            onChange={(e) =>
                              setDrafts((prev) => ({
                                ...prev,
                                [ans.id]: { ...prev[ans.id], examiner_feedback: e.target.value },
                              }))
                            }
                            className="mt-1 w-full rounded border border-slate-300 bg-white px-2.5 py-1.5 text-sm"
                          />
                        </div>
                        <div className="sm:col-span-2">
                          <label htmlFor={`override-${ans.id}`} className="block text-xs font-medium text-slate-700">
                            Override Reason {requiresOverrideReason ? "(Required for auto-graded override)" : "(Optional)"}
                          </label>
                          <input
                            id={`override-${ans.id}`}
                            type="text"
                            value={draft.override_reason}
                            onChange={(e) =>
                              setDrafts((prev) => ({
                                ...prev,
                                [ans.id]: { ...prev[ans.id], override_reason: e.target.value },
                              }))
                            }
                            className="mt-1 w-full rounded border border-slate-300 bg-white px-2.5 py-1.5 text-sm"
                          />
                        </div>
                        <div className="flex items-end">
                          <button
                            type="button"
                            disabled={savingId === ans.id}
                            onClick={() => handleSaveGrade(item)}
                            className="w-full rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                          >
                            {savingId === ans.id ? "Saving..." : "Save Grade"}
                          </button>
                        </div>
                      </div>
                    </div>
                  )}
                </article>
              );
            })}
          </div>

          {(detail.query_runs ?? []).length > 0 ? (
            <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
              <h2 className="text-base font-semibold text-slate-900">Student Query Execution History</h2>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200 text-left text-xs">
                  <thead className="bg-slate-50 text-slate-600">
                    <tr>
                      <th className="px-3 py-2">Status</th>
                      <th className="px-3 py-2">Rows</th>
                      <th className="px-3 py-2">Time (ms)</th>
                      <th className="px-3 py-2">SQL</th>
                      <th className="px-3 py-2">Error</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-200">
                    {detail.query_runs.map((run) => (
                      <tr key={run.id}>
                        <td className="px-3 py-2 font-medium">{run.status}</td>
                        <td className="px-3 py-2">{run.row_count ?? "-"}</td>
                        <td className="px-3 py-2">{run.execution_ms ?? "-"}</td>
                        <td className="px-3 py-2 font-mono">{run.sql_text}</td>
                        <td className="px-3 py-2 text-rose-700">{run.error_message ?? "-"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          ) : null}
        </div>
      ) : null}
    </ExaminerShell>
  );
}
