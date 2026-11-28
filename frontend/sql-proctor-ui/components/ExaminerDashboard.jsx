"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import api, { ensureCsrfCookie, extractApiError } from "../lib/api";

export default function ExaminerDashboard() {
  const router = useRouter();
  const routerRef = useRef(router);

  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const [currentUser, setCurrentUser] = useState(null);
  const [classrooms, setClassrooms] = useState([]);
  const [classroomMeta, setClassroomMeta] = useState(null);
  const [loadedAt, setLoadedAt] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [className, setClassName] = useState("");
  const [codeExpiresAt, setCodeExpiresAt] = useState("");
  const [creating, setCreating] = useState(false);
  const [busyClassroomId, setBusyClassroomId] = useState(null);

  const loadClassrooms = useCallback(async (page = 1, { silent = false } = {}) => {
    if (!silent) {
      setLoading(true);
    }
    try {
      const res = await api.get("/examiner/classes", { params: { page, per_page: 25 } });
      setClassrooms(res.data?.data ?? []);
      setClassroomMeta(res.data?.meta ?? null);
      setLoadedAt(Date.now());
    } catch (err) {
      if (err?.response?.status === 401) {
        routerRef.current.push("/login");
        return;
      }
      setError(extractApiError(err, "Failed to load classrooms.").message);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let active = true;

    async function bootstrap() {
      try {
        const res = await api.get("/user");
        const user = res.data?.data;
        if (!active) return;

        if (!user) {
          routerRef.current.push("/login");
          return;
        }
        if (user.role === "admin") {
          routerRef.current.push("/admin");
          return;
        }
        if (user.role !== "examiner") {
          routerRef.current.push("/login");
          return;
        }

        setCurrentUser(user);
        await loadClassrooms(1);
      } catch (err) {
        if (!active) return;
        const status = err?.response?.status;
        if (status === 401) {
          routerRef.current.push("/login");
        } else {
          setError(extractApiError(err, "Unable to load examiner profile.").message);
        }
      }
    }

    bootstrap();
    return () => {
      active = false;
    };
  }, [loadClassrooms]);

  async function handleLogout() {
    try {
      await ensureCsrfCookie();
      await api.post("/auth/logout");
    } finally {
      router.push("/login");
    }
  }

  async function handleCreateClassroom(event) {
    event.preventDefault();
    setError(null);
    const trimmed = className.trim();
    if (!trimmed) {
      setError("Enter a classroom name.");
      return;
    }

    setCreating(true);
    try {
      await ensureCsrfCookie();
      const payload = { name: trimmed };
      if (codeExpiresAt.trim()) {
        const parsed = new Date(codeExpiresAt.trim());
        payload.code_expires_at = !Number.isNaN(parsed.getTime()) ? parsed.toISOString() : codeExpiresAt.trim();
      }
      await api.post("/examiner/classes", payload);
      setClassName("");
      setCodeExpiresAt("");
      await loadClassrooms(1, { silent: true });
    } catch (err) {
      if (err?.response?.status === 401) {
        router.push("/login");
        return;
      }
      setError(extractApiError(err, "Could not create classroom.").message);
    } finally {
      setCreating(false);
    }
  }

  async function handleClassAction(classroomId, action) {
    if (busyClassroomId !== null) {
      return;
    }
    setError(null);
    setBusyClassroomId(classroomId);
    try {
      await ensureCsrfCookie();
      await api.post(`/examiner/classes/${classroomId}/${action}`);
      await loadClassrooms(classroomMeta?.current_page ?? 1, { silent: true });
    } catch (err) {
      if (err?.response?.status === 401) {
        router.push("/login");
        return;
      }
      setError(extractApiError(err, `Failed to ${action} classroom.`).message);
    } finally {
      setBusyClassroomId(null);
    }
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">
              SQL-Proctor Examiner
            </p>
            <h1 className="text-xl font-semibold text-slate-900">Examiner console</h1>
          </div>
          <div className="flex items-center gap-3">
            {currentUser ? (
              <span className="rounded-full bg-slate-100 px-3 py-1 font-mono text-xs text-slate-700">
                @{currentUser.username}
              </span>
            ) : null}
            <button
              type="button"
              onClick={handleLogout}
              className="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            >
              Sign out
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6">
        {error ? (
          <div
            role="alert"
            className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
          >
            {error}
          </div>
        ) : null}

        <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
          <h2 className="text-base font-semibold text-slate-900">Create classroom</h2>
          <p className="mt-1 text-xs text-slate-600">
            Generate a class code so students can join without passwords.
          </p>

          <form onSubmit={handleCreateClassroom} className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end" noValidate>
            <div className="flex-1">
              <label htmlFor="classroom_name" className="block text-xs font-medium text-slate-700">
                Classroom name
              </label>
              <input
                id="classroom_name"
                type="text"
                aria-label="Classroom name"
                value={className}
                onChange={(e) => setClassName(e.target.value)}
                placeholder="e.g. CS 121 — Practical Exam 1"
                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
              />
            </div>
            <div>
              <label htmlFor="classroom_expires_at" className="block text-xs font-medium text-slate-700">
                Code expires at (optional)
              </label>
              <input
                id="classroom_expires_at"
                type="datetime-local"
                value={codeExpiresAt}
                onChange={(e) => setCodeExpiresAt(e.target.value)}
                className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
              />
            </div>
            <button
              type="submit"
              disabled={creating}
              className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-60"
            >
              Create classroom
            </button>
          </form>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
          <h2 className="mb-4 text-base font-semibold text-slate-900">Your classrooms</h2>

          {loading ? (
            <p role="status" className="text-sm text-slate-600">
              Loading classrooms...
            </p>
          ) : classrooms.length === 0 ? (
            <p className="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">
              No classrooms yet.
            </p>
          ) : (
            <div className="space-y-3">
              {classrooms.map((classroom) => {
                const isExpired = Boolean(
                  loadedAt !== null &&
                    classroom.code_expires_at &&
                    new Date(classroom.code_expires_at).getTime() <= loadedAt,
                );
                return (
                  <div
                    key={classroom.id}
                    className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-slate-200 p-4"
                  >
                    <div>
                      <div className="flex flex-wrap items-center gap-2.5">
                        <h3 className="font-medium text-slate-900">{classroom.name}</h3>
                        <span
                          className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                            classroom.is_open
                              ? "bg-emerald-50 text-emerald-700"
                              : "bg-slate-100 text-slate-600"
                          }`}
                        >
                          {classroom.is_open ? "Open" : "Closed"}
                        </span>
                        {isExpired ? (
                          <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                            Expired
                          </span>
                        ) : null}
                      </div>
                      <p className="mt-1 text-xs text-slate-600">
                        Join code:{" "}
                        <span className="rounded bg-slate-100 px-2 py-0.5 font-mono text-sm font-semibold tracking-widest text-slate-900">
                          {classroom.join_code}
                        </span>
                        <span className="ml-3">
                          Active students: {classroom.active_students_count ?? 0}
                        </span>
                        {classroom.code_expires_at ? (
                          <span className="ml-3 font-mono text-slate-500">
                            Expires: {classroom.code_expires_at}
                          </span>
                        ) : null}
                      </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                      <button
                        type="button"
                        disabled={busyClassroomId === classroom.id}
                        aria-label={`${classroom.is_open ? "Close" : "Open"} ${classroom.name}`}
                        onClick={() =>
                          handleClassAction(classroom.id, classroom.is_open ? "close" : "open")
                        }
                        className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        {classroom.is_open ? "Close" : "Open"}
                      </button>
                      <button
                        type="button"
                        disabled={busyClassroomId === classroom.id}
                        aria-label={`Regenerate code for ${classroom.name}`}
                        onClick={() => handleClassAction(classroom.id, "regenerate-code")}
                        className="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-50"
                      >
                        Regenerate code
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {classroomMeta && classroomMeta.last_page > 1 ? (
            <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-600">
              <span>
                Page {classroomMeta.current_page} of {classroomMeta.last_page} ({classroomMeta.total} total)
              </span>
              <div className="flex gap-2">
                <button
                  type="button"
                  aria-label="Previous page of classrooms"
                  disabled={classroomMeta.current_page <= 1}
                  onClick={() => loadClassrooms(classroomMeta.current_page - 1)}
                  className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
                >
                  Previous
                </button>
                <button
                  type="button"
                  aria-label="Next page of classrooms"
                  disabled={classroomMeta.current_page >= classroomMeta.last_page}
                  onClick={() => loadClassrooms(classroomMeta.current_page + 1)}
                  className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
                >
                  Next
                </button>
              </div>
            </div>
          ) : null}
        </section>
      </main>
    </div>
  );
}
