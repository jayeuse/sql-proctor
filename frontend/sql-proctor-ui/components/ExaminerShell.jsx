"use client";

import { useRouter } from "next/navigation";
import { api, ensureCsrfCookie } from "../lib/api";
import ExaminerNav from "./ExaminerNav";

export default function ExaminerShell({
  title,
  subtitle = "SQL-Proctor Examiner",
  activeSection = "exams",
  currentUser = null,
  children,
}) {
  const router = useRouter();

  async function handleLogout() {
    try {
      await ensureCsrfCookie();
      await api.post("/auth/logout");
    } catch {
      // Redirect even if session already expired.
    } finally {
      router.push("/");
    }
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">
              {subtitle}
            </p>
            <h1 className="truncate text-xl font-semibold text-slate-900">{title}</h1>
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

      <main className="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6">
        <ExaminerNav active={activeSection} />
        {children}
      </main>
    </div>
  );
}
