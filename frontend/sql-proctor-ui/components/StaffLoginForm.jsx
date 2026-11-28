"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import api, { ensureCsrfCookie, extractApiError } from "../lib/api";

export default function StaffLoginForm() {
  const router = useRouter();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setError(null);

    const trimmedUsername = username.trim().toLowerCase();
    if (!trimmedUsername || !password) {
      setError("Enter your username and password.");
      return;
    }

    setPending(true);
    try {
      await ensureCsrfCookie();
      const response = await api.post("/auth/login", {
        username: trimmedUsername,
        password,
      });

      const user = response.data?.data;
      if (user?.role === "admin") {
        router.push("/admin");
      } else {
        router.push("/examiner");
      }
    } catch (err) {
      const parsed = extractApiError(err, "Unable to sign in right now.");
      setError(parsed.message);
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
      <div className="mb-6">
        <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">
          SQL-Proctor Staff
        </p>
        <h1 className="mt-1 text-2xl font-semibold tracking-tight text-slate-900">
          Staff sign in
        </h1>
        <p className="mt-1 text-sm text-slate-600">
          Sign in with your administrator or examiner account.
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
          Signing in...
        </p>
      ) : null}

      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        <div>
          <label htmlFor="username" className="block text-sm font-medium text-slate-700">
            Username
          </label>
          <input
            id="username"
            name="username"
            type="text"
            autoComplete="username"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            className="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
            placeholder="admin or examiner"
          />
        </div>

        <div>
          <label htmlFor="password" className="block text-sm font-medium text-slate-700">
            Password
          </label>
          <input
            id="password"
            name="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
          />
        </div>

        <button
          type="submit"
          disabled={pending}
          className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
        >
          Sign in
        </button>
      </form>

      <div className="mt-6 border-t border-slate-100 pt-4 text-center text-sm text-slate-600">
        Joining an exam as a student?{" "}
        <Link
          href="/"
          className="font-medium text-indigo-600 hover:text-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
        >
          Enter with class code
        </Link>
      </div>
    </div>
  );
}
