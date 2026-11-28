"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api, ensureCsrfCookie, extractApiError } from "../lib/api";
import SchemaSummaryPanel from "./SchemaSummaryPanel";
import SqlEditor from "./SqlEditor";

function formatDuration(totalSeconds) {
  const safe = Math.max(0, Math.floor(totalSeconds ?? 0));
  const hrs = Math.floor(safe / 3600);
  const mins = Math.floor((safe % 3600) / 60);
  const secs = safe % 60;
  const pad = (n) => String(n).padStart(2, "0");
  return hrs > 0 ? `${hrs}:${pad(mins)}:${pad(secs)}` : `${pad(mins)}:${pad(secs)}`;
}

function buildInitialAnswers(questions) {
  const map = {};
  for (const q of questions ?? []) {
    const submittedAt = q.saved_answer?.submitted_at ?? null;
    map[q.id] = {
      submitted_sql: q.saved_answer?.submitted_sql ?? q.starter_sql ?? "",
      submitted_text: q.saved_answer?.submitted_text ?? "",
      selected_option_ids: Array.isArray(q.saved_answer?.selected_option_ids)
        ? q.saved_answer.selected_option_ids
        : [],
      savedAt: q.saved_answer?.updated_at ?? null,
      submittedAt,
      isSubmitted: Boolean(q.saved_answer?.is_submitted || submittedAt),
    };
  }
  return map;
}

export default function StudentExamWorkspace({ attemptId: attemptIdProp }) {
  const params = useParams();
  const router = useRouter();
  const routerRef = useRef(router);
  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const attemptId = attemptIdProp ?? params?.attemptId;

  const [attempt, setAttempt] = useState(null);
  const [answers, setAnswers] = useState({});
  const [activeIndex, setActiveIndex] = useState(0);
  const [remainingSeconds, setRemainingSeconds] = useState(null);
  const [loading, setLoading] = useState(true);
  const [locked, setLocked] = useState(false);
  const [savingQuestionId, setSavingQuestionId] = useState(null);
  const [submittingAnswerId, setSubmittingAnswerId] = useState(null);
  const [confirmAnswerQuestionId, setConfirmAnswerQuestionId] = useState(null);
  const [running, setRunning] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [confirmSubmit, setConfirmSubmit] = useState(false);
  const [queryResult, setQueryResult] = useState(null);
  const [error, setError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");
  const [actionFeedback, setActionFeedback] = useState(null);

  const debounceRef = useRef(null);
  const answersRef = useRef(answers);
  useEffect(() => {
    answersRef.current = answers;
  }, [answers]);

  useEffect(() => {
    let active = true;

    async function load() {
      if (!attemptId) {
        return;
      }
      try {
        const res = await api.get(`/student/attempts/${attemptId}`);
        if (!active) {
          return;
        }
        const loaded = res.data?.data;
        if (!loaded) {
          return;
        }
        setAttempt(loaded);
        setAnswers(buildInitialAnswers(loaded.questions));
        setRemainingSeconds(loaded.remaining_seconds ?? 0);
        if (loaded.status !== "in_progress") {
          setLocked(true);
        }
      } catch (err) {
        if (!active) {
          return;
        }
        const apiErr = extractApiError(err, "Failed to load exam workspace.");
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
      if (debounceRef.current) {
        clearTimeout(debounceRef.current);
      }
    };
  }, [attemptId]);

  useEffect(() => {
    if (!attempt || locked) {
      return undefined;
    }
    const timer = setInterval(() => {
      setRemainingSeconds((prev) => {
        if (prev === null) {
          return prev;
        }
        if (prev <= 1) {
          setLocked(true);
          return 0;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(timer);
  }, [attempt, locked]);

  const questions = useMemo(() => attempt?.questions ?? [], [attempt]);
  const activeQuestion = questions[activeIndex] ?? null;
  const activeAnswer = activeQuestion ? answers[activeQuestion.id] : null;
  const isActiveSubmitted = Boolean(activeAnswer?.isSubmitted);
  const isQuestionLocked = locked || isActiveSubmitted;
  const submittedCount = useMemo(
    () => questions.filter((q) => Boolean(answers[q.id]?.isSubmitted)).length,
    [questions, answers],
  );

  const persistAnswer = useCallback(
    async (question, overrideAnswer = null, { submit = false } = {}) => {
      if (!attemptId || !question || locked || answersRef.current[question.id]?.isSubmitted) {
        return;
      }
      const draft = overrideAnswer ?? answersRef.current[question.id];
      if (!draft) {
        return;
      }
      if (submit && debounceRef.current) {
        clearTimeout(debounceRef.current);
      }

      setError("");
      if (submit) {
        setSubmittingAnswerId(question.id);
        setSaveStatus("Submitting answer...");
        setActionFeedback({ tone: "info", text: "Submitting and locking your answer..." });
      } else {
        setSavingQuestionId(question.id);
        setSaveStatus("Saving draft...");
        setActionFeedback({ tone: "info", text: "Saving draft..." });
      }

      const payload = { submit_answer: submit };
      if (question.question_type.startsWith("sql_")) {
        payload.submitted_sql = draft.submitted_sql ?? "";
      } else if (question.question_type === "short_answer") {
        payload.submitted_text = draft.submitted_text ?? "";
      } else if (question.question_type === "mcq") {
        payload.selected_option_ids = draft.selected_option_ids ?? [];
      }

      try {
        await ensureCsrfCookie();
        const res = await api.put(`/student/attempts/${attemptId}/answers/${question.id}`, payload);
        const saved = res.data?.data ?? {};
        const updatedAt = saved.updated_at ?? new Date().toISOString();
        const submittedAt = submit ? (saved.submitted_at ?? updatedAt) : (saved.submitted_at ?? null);
        const nowSubmitted = Boolean(submit || saved.is_submitted || submittedAt);

        setAnswers((prev) => ({
          ...prev,
          [question.id]: {
            ...(prev[question.id] ?? draft),
            savedAt: updatedAt,
            submittedAt,
            isSubmitted: nowSubmitted,
          },
        }));

        if (nowSubmitted) {
          setConfirmAnswerQuestionId(null);
          setSaveStatus("Answer submitted");
          setActionFeedback({
            tone: "success",
            text: "Answer submitted and locked. Your response remains visible above and cannot be edited.",
          });
        } else {
          setSaveStatus("Draft saved");
          setActionFeedback({ tone: "success", text: "Draft saved." });
        }
      } catch (err) {
        const apiErr = extractApiError(err, "Failed to save answer.");
        if (apiErr.code === "attempt_expired" || apiErr.code === "attempt_not_active") {
          setLocked(true);
        } else if (apiErr.code === "answer_locked") {
          setConfirmAnswerQuestionId(null);
          setAnswers((prev) => ({
            ...prev,
            [question.id]: {
              ...(prev[question.id] ?? draft),
              isSubmitted: true,
              submittedAt: prev[question.id]?.submittedAt ?? new Date().toISOString(),
            },
          }));
        }
        setSaveStatus("");
        setActionFeedback(null);
        setError(apiErr.message);
      } finally {
        setSavingQuestionId(null);
        setSubmittingAnswerId(null);
      }
    },
    [attemptId, locked],
  );

  function scheduleAutoSave(question, nextDraft) {
    if (locked || answersRef.current[question.id]?.isSubmitted) {
      return;
    }
    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
    }
    setSaveStatus("Unsaved changes");
    debounceRef.current = setTimeout(() => {
      persistAnswer(question, nextDraft);
    }, 800);
  }

  function updateActiveAnswer(updater) {
    if (!activeQuestion || locked || answersRef.current[activeQuestion.id]?.isSubmitted) {
      return;
    }
    setAnswers((prev) => {
      const current = prev[activeQuestion.id] ?? {
        submitted_sql: "",
        submitted_text: "",
        selected_option_ids: [],
        savedAt: null,
        submittedAt: null,
        isSubmitted: false,
      };
      const next = typeof updater === "function" ? updater(current) : { ...current, ...updater };
      scheduleAutoSave(activeQuestion, next);
      return {
        ...prev,
        [activeQuestion.id]: next,
      };
    });
  }

  function handleResetStarter() {
    if (!activeQuestion || isQuestionLocked) {
      return;
    }
    setError("");
    setQueryResult(null);
    updateActiveAnswer({ submitted_sql: activeQuestion.starter_sql ?? "" });
    setActionFeedback({
      tone: "info",
      text: "Starter SQL restored (draft not submitted yet).",
    });
  }

  async function handleRunQuery() {
    if (!activeQuestion || locked || answersRef.current[activeQuestion.id]?.isSubmitted) {
      return;
    }
    setError("");
    setQueryResult(null);
    setRunning(true);
    setActionFeedback({ tone: "info", text: "Executing query in MariaDB sandbox..." });

    const sql = answersRef.current[activeQuestion.id]?.submitted_sql ?? "";

    try {
      await ensureCsrfCookie();
      const res = await api.post(`/student/attempts/${attemptId}/questions/${activeQuestion.id}/run`, {
        sql,
      });
      const result = res.data?.data;
      setQueryResult(result);
      if (result?.status === "succeeded") {
        const count = result.row_count ?? 0;
        setActionFeedback({
          tone: "success",
          text: `Query succeeded in ${result.execution_ms ?? 0} ms (${count} ${count === 1 ? "row" : "rows"}${
            result.truncated ? ", truncated" : ""
          }).`,
        });
      } else {
        setActionFeedback({
          tone: "error",
          text: `Query finished with status "${result?.status ?? "error"}" (${result?.execution_ms ?? 0} ms).`,
        });
      }
    } catch (err) {
      const apiErr = extractApiError(err, "Query execution failed.");
      if (apiErr.code === "attempt_expired" || apiErr.code === "attempt_not_active") {
        setLocked(true);
      } else if (apiErr.code === "answer_locked") {
        setConfirmAnswerQuestionId(null);
        setAnswers((prev) => ({
          ...prev,
          [activeQuestion.id]: {
            ...(prev[activeQuestion.id] ?? {}),
            isSubmitted: true,
          },
        }));
      }
      setActionFeedback(null);
      setError(apiErr.message);
    } finally {
      setRunning(false);
    }
  }

  async function handleSubmitExam() {
    if (!attemptId) {
      return;
    }
    setError("");
    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
    }
    setConfirmAnswerQuestionId(null);
    setSubmitting(true);

    try {
      await ensureCsrfCookie();
      if (activeQuestion && !locked && !answersRef.current[activeQuestion.id]?.isSubmitted) {
        await persistAnswer(activeQuestion);
      }
      await api.post(`/student/attempts/${attemptId}/submit`);
      router.push(`/student/exams/${attemptId}/result`);
    } catch (err) {
      const apiErr = extractApiError(err, "Failed to submit exam.");
      if (apiErr.code === "attempt_expired" || apiErr.code === "attempt_not_active") {
        setLocked(true);
      }
      setError(apiErr.message);
    } finally {
      setSubmitting(false);
      setConfirmSubmit(false);
    }
  }

  if (loading) {
    return (
      <main className="mx-auto max-w-6xl px-4 py-10">
        <p role="status" className="text-sm text-slate-600">
          Loading exam workspace...
        </p>
      </main>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">
              SQL-Proctor Exam Workspace
            </p>
            <h1 className="truncate text-lg font-semibold text-slate-900">
              {attempt?.title ?? "Exam Attempt"}
            </h1>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            <span className="rounded bg-slate-100 px-2.5 py-1 font-mono text-xs font-medium text-slate-700 tabular-nums">
              {submittedCount} / {questions.length} submitted
            </span>
            {saveStatus ? (
              <span
                className={`rounded px-2.5 py-1 text-xs font-medium ${
                  saveStatus === "Answer submitted" || saveStatus === "Draft saved"
                    ? "bg-emerald-50 text-emerald-800"
                    : saveStatus.includes("Saving") || saveStatus.includes("Submitting")
                      ? "bg-indigo-50 text-indigo-800"
                      : "bg-amber-50 text-amber-800"
                }`}
              >
                {saveStatus}
              </span>
            ) : null}
            {remainingSeconds !== null ? (
              <span
                aria-label="Remaining time"
                className={`rounded-lg px-3 py-1 font-mono text-sm font-semibold tabular-nums ${
                  remainingSeconds <= 300 ? "bg-amber-100 text-amber-900" : "bg-slate-900 text-white"
                }`}
              >
                {formatDuration(remainingSeconds)}
              </span>
            ) : null}
            {!locked ? (
              <button
                type="button"
                disabled={submitting}
                onClick={() => setConfirmSubmit(true)}
                className="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
              >
                Submit Exam
              </button>
            ) : (
              <Link
                href={`/student/exams/${attemptId}/result`}
                className="rounded-lg bg-slate-900 px-4 py-1.5 text-sm font-semibold text-white hover:bg-slate-800"
              >
                View Result
              </Link>
            )}
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl space-y-4 px-4 py-5 sm:px-6">
        {confirmSubmit ? (
          <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
            <p className="text-sm font-medium text-indigo-950">
              Ready to submit this exam? Answers cannot be changed after submission.
            </p>
            <div className="flex gap-2">
              <button
                type="button"
                disabled={submitting}
                onClick={handleSubmitExam}
                className="rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
              >
                {submitting ? "Submitting..." : "Confirm Submit"}
              </button>
              <button
                type="button"
                onClick={() => setConfirmSubmit(false)}
                className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700"
              >
                Cancel
              </button>
            </div>
          </div>
        ) : null}

        {error ? (
          <div role="alert" className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {error}
          </div>
        ) : null}

        {attempt ? (
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-12">
            <aside className="space-y-4 lg:col-span-4">
              <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                <h2 className="text-sm font-semibold text-slate-900">Questions</h2>
                <div className="flex flex-wrap gap-2">
                  {questions.map((q, idx) => {
                    const isCurrent = idx === activeIndex;
                    const ans = answers[q.id];
                    const qSubmitted = Boolean(ans?.isSubmitted);
                    const isSaved = Boolean(ans?.savedAt);
                    return (
                      <button
                        key={q.id}
                        type="button"
                        onClick={() => {
                          setActiveIndex(idx);
                          setQueryResult(null);
                          setConfirmAnswerQuestionId(null);
                          setActionFeedback(null);
                        }}
                        className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${
                          isCurrent
                            ? qSubmitted
                              ? "border border-emerald-700 bg-slate-900 text-emerald-300"
                              : "bg-slate-900 text-white"
                            : qSubmitted
                              ? "border border-emerald-300 bg-emerald-50 text-emerald-800"
                              : isSaved
                                ? "border border-indigo-200 bg-indigo-50 text-indigo-800"
                                : "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                        }`}
                      >
                        Q{idx + 1}
                        {qSubmitted ? " ✓" : isSaved ? " •" : ""}
                      </button>
                    );
                  })}
                </div>
                <p className="text-[11px] text-slate-500">
                  ✓ Submitted &amp; locked · • Draft saved
                </p>
                {attempt.instructions ? (
                  <p className="border-t border-slate-200 pt-3 text-xs whitespace-pre-line text-slate-600">
                    {attempt.instructions}
                  </p>
                ) : null}
              </section>

              <section className="space-y-2 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                <h2 className="text-sm font-semibold text-slate-900">Database Schema</h2>
                <SchemaSummaryPanel
                  schemaSummary={attempt.schema_summary}
                  emptyText="No schema attached to this exam."
                />
              </section>
            </aside>

            <section className="space-y-4 lg:col-span-8">
              {activeQuestion ? (
                <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Question {activeIndex + 1} of {questions.length} · {activeQuestion.question_type}
                      </p>
                      <h2 className="mt-0.5 text-lg font-semibold text-slate-900">{activeQuestion.title}</h2>
                    </div>
                    {attempt.settings?.show_points && activeQuestion.points ? (
                      <span className="rounded-full bg-slate-100 px-3 py-1 font-mono text-xs font-semibold text-slate-800">
                        {activeQuestion.points} pts
                      </span>
                    ) : null}
                  </div>

                  <p className="whitespace-pre-line text-sm leading-6 text-slate-800">{activeQuestion.prompt}</p>

                  {(activeQuestion.visible_test_cases ?? []).length > 0 ? (
                    <div className="flex flex-wrap gap-2 text-xs">
                      {activeQuestion.visible_test_cases.map((tc) => (
                        <span key={tc.id} className="rounded bg-slate-100 px-2 py-0.5 text-slate-700">
                          {tc.name}
                          {tc.weight_points ? ` (${tc.weight_points} pts)` : ""}
                        </span>
                      ))}
                    </div>
                  ) : null}

                  {isActiveSubmitted ? (
                    <div
                      role="status"
                      className="rounded-lg border border-emerald-300 bg-emerald-50 px-3.5 py-2.5 text-xs font-medium text-emerald-900"
                    >
                      Answer submitted and locked. Your response remains visible below and can no longer be edited or
                      re-executed.
                    </div>
                  ) : null}

                  {activeQuestion.question_type.startsWith("sql_") ? (
                    <div className="space-y-3">
                      <SqlEditor
                        label="Your SQL Query"
                        value={activeAnswer?.submitted_sql ?? ""}
                        readOnly={isQuestionLocked}
                        height={220}
                        onRun={handleRunQuery}
                        onChange={(val) => updateActiveAnswer({ submitted_sql: val })}
                      />
                      <div className="flex flex-wrap items-center gap-2">
                        <button
                          type="button"
                          disabled={isQuestionLocked || running || !attempt.settings?.allow_query_preview}
                          onClick={handleRunQuery}
                          className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          {running ? (
                            <>
                              <span
                                aria-hidden="true"
                                className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"
                              />
                              Running Query...
                            </>
                          ) : (
                            "Run Query"
                          )}
                        </button>
                        <button
                          type="button"
                          disabled={
                            isQuestionLocked ||
                            savingQuestionId === activeQuestion.id ||
                            submittingAnswerId === activeQuestion.id
                          }
                          onClick={() => persistAnswer(activeQuestion)}
                          className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          {savingQuestionId === activeQuestion.id ? "Saving Draft..." : "Save Draft"}
                        </button>
                        {activeQuestion.starter_sql ? (
                          <button
                            type="button"
                            disabled={isQuestionLocked || submittingAnswerId === activeQuestion.id}
                            onClick={handleResetStarter}
                            className="rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                          >
                            Reset Starter SQL
                          </button>
                        ) : null}
                        {!isQuestionLocked ? (
                          <button
                            type="button"
                            disabled={
                              savingQuestionId === activeQuestion.id || submittingAnswerId === activeQuestion.id
                            }
                            onClick={() => setConfirmAnswerQuestionId(activeQuestion.id)}
                            className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                          >
                            Submit Answer
                          </button>
                        ) : null}
                      </div>
                    </div>
                  ) : null}

                  {activeQuestion.question_type === "mcq" ? (
                    <div className="space-y-3">
                      <fieldset className="space-y-2">
                        <legend className="text-xs font-medium text-slate-700">Select your answer:</legend>
                        {(activeQuestion.options ?? []).map((opt) => {
                          const checked = (activeAnswer?.selected_option_ids ?? []).includes(opt.id);
                          return (
                            <label
                              key={opt.id}
                              className={`flex items-center gap-2.5 rounded-lg border px-3.5 py-2.5 text-sm ${
                                isQuestionLocked
                                  ? checked
                                    ? "cursor-not-allowed border-emerald-300 bg-emerald-50/50"
                                    : "cursor-not-allowed border-slate-200 bg-slate-50 opacity-75"
                                  : "cursor-pointer border-slate-200 hover:bg-slate-50"
                              }`}
                            >
                              <input
                                type="checkbox"
                                disabled={isQuestionLocked}
                                checked={checked}
                                onChange={(e) => {
                                  const prevIds = activeAnswer?.selected_option_ids ?? [];
                                  const nextIds = e.target.checked
                                    ? [...new Set([...prevIds, opt.id])]
                                    : prevIds.filter((id) => id !== opt.id);
                                  updateActiveAnswer({ selected_option_ids: nextIds });
                                }}
                              />
                              <span className="text-slate-900">{opt.label}</span>
                            </label>
                          );
                        })}
                      </fieldset>
                      <div className="flex flex-wrap items-center gap-2">
                        <button
                          type="button"
                          disabled={
                            isQuestionLocked ||
                            savingQuestionId === activeQuestion.id ||
                            submittingAnswerId === activeQuestion.id
                          }
                          onClick={() => persistAnswer(activeQuestion)}
                          className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          {savingQuestionId === activeQuestion.id ? "Saving Draft..." : "Save Draft"}
                        </button>
                        {!isQuestionLocked ? (
                          <button
                            type="button"
                            disabled={
                              savingQuestionId === activeQuestion.id || submittingAnswerId === activeQuestion.id
                            }
                            onClick={() => setConfirmAnswerQuestionId(activeQuestion.id)}
                            className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                          >
                            Submit Answer
                          </button>
                        ) : null}
                      </div>
                    </div>
                  ) : null}

                  {activeQuestion.question_type === "short_answer" ? (
                    <div className="space-y-3">
                      <label htmlFor="short-answer-input" className="block text-xs font-medium text-slate-700">
                        Your Answer
                      </label>
                      <textarea
                        id="short-answer-input"
                        rows={5}
                        disabled={isQuestionLocked}
                        value={activeAnswer?.submitted_text ?? ""}
                        onChange={(e) => updateActiveAnswer({ submitted_text: e.target.value })}
                        placeholder="Write your explanation here..."
                        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-700"
                      />
                      <div className="flex flex-wrap items-center gap-2">
                        <button
                          type="button"
                          disabled={
                            isQuestionLocked ||
                            savingQuestionId === activeQuestion.id ||
                            submittingAnswerId === activeQuestion.id
                          }
                          onClick={() => persistAnswer(activeQuestion)}
                          className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          {savingQuestionId === activeQuestion.id ? "Saving Draft..." : "Save Draft"}
                        </button>
                        {!isQuestionLocked ? (
                          <button
                            type="button"
                            disabled={
                              savingQuestionId === activeQuestion.id || submittingAnswerId === activeQuestion.id
                            }
                            onClick={() => setConfirmAnswerQuestionId(activeQuestion.id)}
                            className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                          >
                            Submit Answer
                          </button>
                        ) : null}
                      </div>
                    </div>
                  ) : null}

                  {confirmAnswerQuestionId === activeQuestion.id && !isQuestionLocked ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3.5">
                      <p className="text-xs font-medium text-amber-950">
                        Submit your answer for Q{activeIndex + 1}? Once submitted, your response remains visible and you
                        cannot edit or run queries for this question.
                      </p>
                      <div className="flex gap-2">
                        <button
                          type="button"
                          disabled={submittingAnswerId === activeQuestion.id}
                          onClick={() => persistAnswer(activeQuestion, null, { submit: true })}
                          className="rounded-lg bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                          {submittingAnswerId === activeQuestion.id
                            ? "Submitting Answer..."
                            : "Confirm Submit Answer"}
                        </button>
                        <button
                          type="button"
                          disabled={submittingAnswerId === activeQuestion.id}
                          onClick={() => setConfirmAnswerQuestionId(null)}
                          className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                        >
                          Cancel
                        </button>
                      </div>
                    </div>
                  ) : null}

                  {actionFeedback && !isActiveSubmitted ? (
                    <div
                      role={actionFeedback.tone === "error" ? undefined : "status"}
                      className={`rounded-lg border px-3 py-2 text-xs font-medium ${
                        actionFeedback.tone === "success"
                          ? "border-emerald-200 bg-emerald-50 text-emerald-800"
                          : actionFeedback.tone === "error"
                            ? "border-rose-200 bg-rose-50 text-rose-800"
                            : "border-indigo-200 bg-indigo-50 text-indigo-800"
                      }`}
                    >
                      {actionFeedback.text}
                    </div>
                  ) : null}
                </div>
              ) : null}

              {running ? (
                <section
                  role="status"
                  className="flex items-center gap-2.5 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-xs font-medium text-indigo-900"
                >
                  <span
                    aria-hidden="true"
                    className="h-4 w-4 animate-spin rounded-full border-2 border-indigo-600 border-t-transparent"
                  />
                  Executing query in isolated MariaDB sandbox...
                </section>
              ) : null}

              {queryResult ? (
                <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold text-slate-900">Query Output</h3>
                    <span className="font-mono text-xs text-slate-600">
                      {queryResult.status} · {queryResult.row_count} rows
                      {queryResult.truncated ? " (truncated)" : ""} · {queryResult.execution_ms} ms
                    </span>
                  </div>

                  {queryResult.error ? (
                    <div
                      role="alert"
                      className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 font-mono text-xs text-rose-800"
                    >
                      {queryResult.error}
                    </div>
                  ) : (queryResult.columns ?? []).length === 0 ? (
                    <p className="text-xs text-slate-600">Statement executed with no result set.</p>
                  ) : (
                    <div className="overflow-x-auto rounded-lg border border-slate-200">
                      <table className="min-w-full divide-y divide-slate-200 text-left text-xs">
                        <thead className="bg-slate-50 font-mono text-slate-700">
                          <tr>
                            {queryResult.columns.map((col, idx) => (
                              <th key={`${col}-${idx}`} className="px-3 py-2">
                                {col}
                              </th>
                            ))}
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200 font-mono">
                          {(() => {
                            const rowKeys = [];
                            const seenKeys = new Set();
                            for (const col of queryResult.columns) {
                              let key = col;
                              let suffix = 2;
                              while (seenKeys.has(key)) {
                                key = `${col}_${suffix}`;
                                suffix += 1;
                              }
                              seenKeys.add(key);
                              rowKeys.push(key);
                            }

                            return (queryResult.rows ?? []).map((row, rIdx) => (
                              <tr key={rIdx}>
                                {rowKeys.map((rowKey, cIdx) => {
                                  const cell = row?.[rowKey];
                                  return (
                                    <td key={`${rIdx}-${cIdx}`} className="px-3 py-2 text-slate-800">
                                      {cell === null || cell === undefined ? (
                                        <span className="italic text-slate-400">NULL</span>
                                      ) : (
                                        String(cell)
                                      )}
                                    </td>
                                  );
                                })}
                              </tr>
                            ));
                          })()}
                        </tbody>
                      </table>
                    </div>
                  )}
                </section>
              ) : null}
            </section>
          </div>
        ) : null}
      </main>
    </div>
  );
}
