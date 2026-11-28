"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import api, { ensureCsrfCookie, extractApiError } from "../lib/api";

export default function StudentJoinForm() {
  const router = useRouter();
  const [displayName, setDisplayName] = useState("");
  const [classCode, setClassCode] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setError(null);

    const normalizedName = displayName.trim().replace(/\s+/g, " ");
    const normalizedCode = classCode.trim().toUpperCase();

    if (!normalizedName || !normalizedCode) {
      setError("Enter your display name and class code.");
      return;
    }

    setPending(true);
    try {
      await ensureCsrfCookie();
      await api.post("/student/join", {
        display_name: normalizedName,
        class_code: normalizedCode,
      });

      router.push("/student");
    } catch (err) {
      const parsed = extractApiError(err, "Unable to join class.");
      setError(parsed.message);
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
      <div className="mb-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">
          SQL-Proctor
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
          Join a class
        </h1>
        <p className="mt-1 text-sm text-slate-600">
          Enter your name and the class code provided by your examiner.
        </p>
      </div>

      {error ? (
        <div
          role="alert"
          className="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-sm text-rose-800"
        >
          {error}
        </div>
      ) : null}

      {pending ? (
        <p role="status" className="mb-4 text-sm font-medium text-indigo-700">
          Joining class...
        </p>
      ) : null}

      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        <div>
          <label htmlFor="display_name" className="block text-sm font-medium text-slate-700">
            Display name
          </label>
          <input
            id="display_name"
            name="display_name"
            type="text"
            autoComplete="name"
            maxLength={64}
            value={displayName}
            onChange={(e) => setDisplayName(e.target.value)}
            className="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            placeholder="Ada Lovelace"
          />
        </div>

        <div>
          <label htmlFor="class_code" className="block text-sm font-medium text-slate-700">
            Class code
          </label>
          <input
            id="class_code"
            name="class_code"
            type="text"
            autoCapitalize="characters"
            value={classCode}
            onChange={(e) =>
              setClassCode(e.target.value.replace(/[^a-zA-Z0-9]/g, "").toUpperCase().slice(0, 8))
            }
            className="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 font-mono text-sm uppercase tracking-widest text-slate-900 shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            placeholder="ABCD2345"
          />
        </div>

        <button
          type="submit"
          disabled={pending}
          className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
        >
          Join class
        </button>
      </form>

      <div className="mt-6 border-t border-slate-100 pt-4 text-center text-sm text-slate-600">
        Admin or examiner?{" "}
        <Link
          href="/login"
          className="font-medium text-indigo-600 hover:text-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
        >
          Staff sign in
        </Link>
      </div>
    </div>
  );
}
