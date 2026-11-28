"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import api, { ensureCsrfCookie, extractApiError } from "../lib/api";

export default function StudentSessionView() {
  const router = useRouter();
  const routerRef = useRef(router);

  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const [session, setSession] = useState(null);
  const [assignedExams, setAssignedExams] = useState([]);
  const [startingExamId, setStartingExamId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [leaving, setLeaving] = useState(false);
  const [error, setError] = useState(null);
  const [errorCode, setErrorCode] = useState(null);

  useEffect(() => {
    let active = true;

    async function loadSession() {
      try {
        const res = await api.get("/student/session");
        if (!active) return;
        const sessionData = res.data?.data ?? null;
        setSession(sessionData);

        if (sessionData) {
          try {
            const examsRes = await api.get("/student/exams");
            if (active) {
              setAssignedExams(examsRes.data?.data ?? []);
            }
          } catch (examsErr) {
            if (active && examsErr?.response?.status === 401) {
              routerRef.current.push("/");
            }
          }
        }
      } catch (err) {
        if (!active) return;
        const status = err?.response?.status;
        if (status === 401) {
          routerRef.current.push("/");
          return;
        }
        const parsed = extractApiError(err, "Unable to load student session.");
        setError(parsed.message);
        setErrorCode(parsed.code);
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    }

    loadSession();
    return () => {
      active = false;
    };
  }, []);

  async function handleStartExam(classroomExamId) {
    setError(null);
    setStartingExamId(classroomExamId);
    try {
      await ensureCsrfCookie();
      const res = await api.post(`/student/exams/${classroomExamId}/start`);
      const attempt = res.data?.data;
      if (attempt?.id) {
        router.push(`/student/exams/${attempt.id}`);
      }
    } catch (err) {
      const parsed = extractApiError(err, "Could not start exam.");
      if (parsed.status === 401) {
        router.push("/");
        return;
      }
      setError(parsed.message);
    } finally {
      setStartingExamId(null);
    }
  }

  async function handleLeave() {
    setLeaving(true);
    setError(null);
    try {
      await ensureCsrfCookie();
      await api.post("/student/leave");
      router.push("/");
    } catch (err) {
      if (err?.response?.status === 401) {
        router.push("/");
        return;
      }
      const parsed = extractApiError(err, "Could not leave class.");
      setError(parsed.message);
      setErrorCode(parsed.code);
      setLeaving(false);
    }
  }

  return (
    <div className="min-h-screen bg-slate-50 px-4 py-10">
      <main className="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">
          Student session
        </p>
        <h1 className="mt-1 text-2xl font-semibold text-slate-900">Active classroom</h1>

        {error ? (
          <div className="mt-4 space-y-3">
            <div
              role="alert"
              className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
            >
              {error}
            </div>
            <div className="flex flex-wrap items-center gap-3">
              {errorCode === "class_closed" && !session ? (
                <button
                  type="button"
                  disabled={leaving}
                  onClick={handleLeave}
                  className="rounded-lg border border-rose-300 bg-rose-50 px-3.5 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 disabled:opacity-60"
                >
                  Leave Class
                </button>
              ) : null}
              <Link
                href="/"
                className="inline-block text-sm font-medium text-indigo-600 hover:text-indigo-700"
              >
                Return to class join
              </Link>
            </div>
          </div>
        ) : null}

        {loading ? (
          <p role="status" className="mt-4 text-sm text-slate-600">
            Loading session...
          </p>
        ) : session ? (
          <div className="mt-6 space-y-4">
            <dl className="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-slate-50/70 px-4 text-sm">
              <div className="flex justify-between gap-4 py-3">
                <dt className="text-slate-600">Display name</dt>
                <dd className="font-semibold text-slate-900">{session.display_name}</dd>
              </div>
              <div className="flex justify-between gap-4 py-3">
                <dt className="text-slate-600">Classroom</dt>
                <dd className="font-medium text-slate-900">{session.classroom?.name}</dd>
              </div>
              <div className="flex justify-between gap-4 py-3">
                <dt className="text-slate-600">Class code</dt>
                <dd className="font-mono font-semibold tracking-widest text-slate-900">
                  {session.classroom?.join_code}
                </dd>
              </div>
              <div className="flex justify-between gap-4 py-3">
                <dt className="text-slate-600">Session expires</dt>
                <dd className="font-mono text-xs text-slate-700">{session.expires_at}</dd>
              </div>
            </dl>

            <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
              <h2 className="text-sm font-semibold text-slate-900">Assigned SQL Exams</h2>
              {assignedExams.length === 0 ? (
                <p className="text-xs text-slate-600">
                  No active exams are currently assigned to this classroom.
                </p>
              ) : (
                <ul className="space-y-3">
                  {assignedExams.map((exam) => {
                    const attempt = exam.attempt;
                    return (
                      <li
                        key={exam.id}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3"
                      >
                        <div className="min-w-0">
                          <p className="text-sm font-semibold text-slate-900">{exam.title}</p>
                          <p className="text-xs text-slate-600">
                            {exam.duration_minutes} min · {exam.question_count} questions · {exam.total_points} pts
                          </p>
                          {attempt?.released ? (
                            <p className="mt-1 font-mono text-xs text-emerald-700">
                              Score: {attempt.final_score} / {attempt.max_score} (
                              {attempt.passed ? "Passed" : "Not Passed"})
                            </p>
                          ) : null}
                        </div>
                        <div className="shrink-0">
                          {!attempt ? (
                            <button
                              type="button"
                              disabled={startingExamId === exam.id}
                              onClick={() => handleStartExam(exam.id)}
                              className="rounded-lg bg-indigo-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                            >
                              {startingExamId === exam.id ? "Starting..." : "Start Exam"}
                            </button>
                          ) : attempt.status === "in_progress" ? (
                            <Link
                              href={`/student/exams/${attempt.id}`}
                              className="inline-block rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                            >
                              Resume Exam
                            </Link>
                          ) : (
                            <Link
                              href={`/student/exams/${attempt.id}/result`}
                              className="inline-block rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                              View Result
                            </Link>
                          )}
                        </div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </section>

            <button
              type="button"
              disabled={leaving}
              onClick={handleLeave}
              className="w-full rounded-lg border border-rose-300 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 disabled:opacity-60"
            >
              Leave Class
            </button>
          </div>
        ) : null}
      </main>
    </div>
  );
}
