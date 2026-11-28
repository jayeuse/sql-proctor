"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import api, { ensureCsrfCookie, extractApiError } from "../lib/api";

export default function AdminDashboard() {
  const router = useRouter();
  const routerRef = useRef(router);

  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const [currentUser, setCurrentUser] = useState(null);
  const [activeTab, setActiveTab] = useState("examiners");
  const [error, setError] = useState(null);
  const [oneTimeSecret, setOneTimeSecret] = useState(null);

  // Examiner state
  const [examiners, setExaminers] = useState([]);
  const [examinerMeta, setExaminerMeta] = useState(null);
  const [examinersLoading, setExaminersLoading] = useState(true);
  const [searchUsername, setSearchUsername] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");

  // Create examiner form state
  const [newUsername, setNewUsername] = useState("");
  const [newName, setNewName] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [creating, setCreating] = useState(false);
  const [busyExaminerId, setBusyExaminerId] = useState(null);

  // Audit logs state
  const [auditLogs, setAuditLogs] = useState([]);
  const [auditMeta, setAuditMeta] = useState(null);
  const [auditLoading, setAuditLoading] = useState(false);
  const [auditFilters, setAuditFilters] = useState({
    actor_type: "",
    event_type: "",
    target_type: "",
    from: "",
    to: "",
  });

  const loadExaminers = useCallback(async (page = 1, username = "", status = "all", { silent = false } = {}) => {
    if (!silent) {
      setExaminersLoading(true);
    }
    try {
      const params = { page, per_page: 25 };
      if (username.trim()) {
        params.username = username.trim();
      }
      if (status !== "all") {
        params.is_active = status === "active" ? "true" : "false";
      }
      const res = await api.get("/admin/examiners", { params });
      setExaminers(res.data?.data ?? []);
      setExaminerMeta(res.data?.meta ?? null);
    } catch (err) {
      if (err?.response?.status === 401) {
        routerRef.current.push("/login");
        return;
      }
      setError(extractApiError(err, "Failed to load examiners.").message);
    } finally {
      setExaminersLoading(false);
    }
  }, []);

  const loadAuditLogs = useCallback(async (page = 1, filters = {}) => {
    setAuditLoading(true);
    try {
      const params = { page, per_page: 25 };
      for (const [key, value] of Object.entries(filters)) {
        const trimmed = value ? String(value).trim() : "";
        if (trimmed === "") {
          continue;
        }
        if (key === "from" || key === "to") {
          const parsed = new Date(trimmed);
          params[key] = !Number.isNaN(parsed.getTime()) ? parsed.toISOString() : trimmed;
        } else {
          params[key] = trimmed;
        }
      }
      const res = await api.get("/audit-logs", { params });
      setAuditLogs(res.data?.data ?? []);
      setAuditMeta(res.data?.meta ?? null);
    } catch (err) {
      if (err?.response?.status === 401) {
        routerRef.current.push("/login");
        return;
      }
      setError(extractApiError(err, "Failed to load audit logs.").message);
    } finally {
      setAuditLoading(false);
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
        if (user.role === "examiner") {
          routerRef.current.push("/examiner");
          return;
        }
        if (user.role !== "admin") {
          routerRef.current.push("/login");
          return;
        }

        setCurrentUser(user);
        await loadExaminers(1, "", "all");
      } catch (err) {
        if (!active) return;
        const status = err?.response?.status;
        if (status === 401) {
          routerRef.current.push("/login");
        } else if (status === 403) {
          routerRef.current.push("/examiner");
        } else {
          setError(extractApiError(err, "Unable to verify session.").message);
        }
      }
    }

    bootstrap();
    return () => {
      active = false;
    };
  }, [loadExaminers]);

  async function handleLogout() {
    try {
      await ensureCsrfCookie();
      await api.post("/auth/logout");
    } finally {
      router.push("/login");
    }
  }

  async function handleCreateExaminer(event) {
    event.preventDefault();
    setError(null);
    setOneTimeSecret(null);

    if (!newUsername.trim()) {
      setError("Enter a username for the new examiner.");
      return;
    }

    setCreating(true);
    try {
      await ensureCsrfCookie();
      const payload = { username: newUsername.trim().toLowerCase() };
      if (newName.trim()) {
        payload.name = newName.trim();
      }
      if (newPassword) {
        payload.password = newPassword;
      }

      const res = await api.post("/admin/examiners", payload);
      const created = res.data?.data;
      if (created?.generated_password) {
        setOneTimeSecret({
          username: created.username,
          password: created.generated_password,
          action: "created",
        });
      }

      setNewUsername("");
      setNewName("");
      setNewPassword("");
      await loadExaminers(1, searchUsername, statusFilter, { silent: true });
    } catch (err) {
      if (err?.response?.status === 401) {
        router.push("/login");
        return;
      }
      setError(extractApiError(err, "Could not create examiner.").message);
    } finally {
      setCreating(false);
    }
  }

  async function handleToggleActive(examiner) {
    if (busyExaminerId !== null) {
      return;
    }
    setError(null);
    setBusyExaminerId(examiner.id);
    try {
      await ensureCsrfCookie();
      await api.patch(`/admin/examiners/${examiner.id}`, {
        is_active: !examiner.is_active,
      });
      await loadExaminers(examinerMeta?.current_page ?? 1, searchUsername, statusFilter, { silent: true });
    } catch (err) {
      if (err?.response?.status === 401) {
        router.push("/login");
        return;
      }
      setError(extractApiError(err, "Could not update examiner status.").message);
    } finally {
      setBusyExaminerId(null);
    }
  }

  async function handleResetPassword(examiner) {
    if (busyExaminerId !== null) {
      return;
    }
    setError(null);
    setOneTimeSecret(null);
    setBusyExaminerId(examiner.id);
    try {
      await ensureCsrfCookie();
      const res = await api.post(`/admin/examiners/${examiner.id}/reset-password`);
      const updated = res.data?.data;
      if (updated?.generated_password) {
        setOneTimeSecret({
          username: updated.username,
          password: updated.generated_password,
          action: "password reset",
        });
      }
      await loadExaminers(examinerMeta?.current_page ?? 1, searchUsername, statusFilter, { silent: true });
    } catch (err) {
      if (err?.response?.status === 401) {
        router.push("/login");
        return;
      }
      setError(extractApiError(err, "Could not reset password.").message);
    } finally {
      setBusyExaminerId(null);
    }
  }

  function handleTabSwitch(tab) {
    setActiveTab(tab);
    setError(null);
    if (tab !== "examiners") {
      setOneTimeSecret(null);
    }
    if (tab === "audit") {
      loadAuditLogs(1, auditFilters);
    }
  }

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-indigo-600">
              SQL-Proctor Admin
            </p>
            <h1 className="text-xl font-semibold text-slate-900">
              Administration console
            </h1>
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
        <div role="tablist" aria-label="Admin sections" className="flex gap-2 border-b border-slate-200">
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === "examiners"}
            onClick={() => handleTabSwitch("examiners")}
            className={`border-b-2 px-4 py-2.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 ${
              activeTab === "examiners"
                ? "border-indigo-600 text-indigo-600"
                : "border-transparent text-slate-600 hover:text-slate-900"
            }`}
          >
            Examiners
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={activeTab === "audit"}
            onClick={() => handleTabSwitch("audit")}
            className={`border-b-2 px-4 py-2.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 ${
              activeTab === "audit"
                ? "border-indigo-600 text-indigo-600"
                : "border-transparent text-slate-600 hover:text-slate-900"
            }`}
          >
            Audit Logs
          </button>
        </div>

        {error ? (
          <div
            role="alert"
            className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
          >
            {error}
          </div>
        ) : null}

        {oneTimeSecret ? (
          <div
            role="status"
            className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
          >
            <p className="font-semibold">
              One-time password for <span className="font-mono">{oneTimeSecret.username}</span> ({oneTimeSecret.action}):
            </p>
            <p className="mt-1.5 select-all rounded-md bg-white px-3 py-2 font-mono text-sm font-semibold text-slate-900 ring-1 ring-amber-200">
              {oneTimeSecret.password}
            </p>
            <p className="mt-1.5 text-xs text-amber-800">
              Copy and share securely now. This plaintext password will not be shown again.
            </p>
          </div>
        ) : null}

        {activeTab === "examiners" ? (
          <div className="grid gap-6 lg:grid-cols-3">
            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
              <h2 className="text-base font-semibold text-slate-900">Create examiner</h2>
              <p className="mt-1 text-xs text-slate-600">
                Leave password blank to generate a secure random password once.
              </p>

              <form onSubmit={handleCreateExaminer} className="mt-4 space-y-3.5" noValidate>
                <div>
                  <label htmlFor="examiner_username" className="block text-sm font-medium text-slate-700">
                    Examiner username
                  </label>
                  <input
                    id="examiner_username"
                    type="text"
                    value={newUsername}
                    onChange={(e) => setNewUsername(e.target.value)}
                    placeholder="prof_delacruz"
                    className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                  />
                </div>
                <div>
                  <label htmlFor="examiner_name" className="block text-sm font-medium text-slate-700">
                    Display name (optional)
                  </label>
                  <input
                    id="examiner_name"
                    type="text"
                    value={newName}
                    onChange={(e) => setNewName(e.target.value)}
                    placeholder="Prof. Maria Dela Cruz"
                    className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                  />
                </div>
                <div>
                  <label htmlFor="examiner_password" className="block text-sm font-medium text-slate-700">
                    Password (optional, min 12 chars)
                  </label>
                  <input
                    id="examiner_password"
                    type="password"
                    value={newPassword}
                    onChange={(e) => setNewPassword(e.target.value)}
                    placeholder="Auto-generate if empty"
                    className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                  />
                </div>
                <button
                  type="submit"
                  disabled={creating}
                  className="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-60"
                >
                  Create examiner
                </button>
              </form>
            </section>

            <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-2">
              <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                  <h2 className="text-base font-semibold text-slate-900">Examiner accounts</h2>
                  <p className="text-xs text-slate-600">
                    Manage active status and reset examiner credentials.
                  </p>
                </div>

                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    loadExaminers(1, searchUsername, statusFilter);
                  }}
                  className="flex flex-wrap items-center gap-2"
                >
                  <input
                    aria-label="Filter by username"
                    type="search"
                    value={searchUsername}
                    onChange={(e) => setSearchUsername(e.target.value)}
                    placeholder="Search username"
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                  />
                  <select
                    aria-label="Filter by status"
                    value={statusFilter}
                    onChange={(e) => {
                      const nextStatus = e.target.value;
                      setStatusFilter(nextStatus);
                      loadExaminers(1, searchUsername, nextStatus);
                    }}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                  >
                    <option value="all">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                  <button
                    type="submit"
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                  >
                    Filter
                  </button>
                </form>
              </div>

              {examinersLoading ? (
                <p role="status" className="text-sm text-slate-600">
                  Loading examiners...
                </p>
              ) : examiners.length === 0 ? (
                <p className="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">
                  No examiner accounts found.
                </p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead className="text-xs font-semibold uppercase text-slate-500">
                      <tr>
                        <th className="py-2.5 pr-4">Username</th>
                        <th className="py-2.5 pr-4">Name</th>
                        <th className="py-2.5 pr-4">Status</th>
                        <th className="py-2.5 text-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {examiners.map((examiner) => (
                        <tr key={examiner.id}>
                          <td className="py-3 pr-4 font-mono font-medium text-slate-900">
                            {examiner.username}
                          </td>
                          <td className="py-3 pr-4 text-slate-600">{examiner.name || "—"}</td>
                          <td className="py-3 pr-4">
                            <span
                              className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                examiner.is_active
                                  ? "bg-emerald-50 text-emerald-700"
                                  : "bg-slate-100 text-slate-600"
                              }`}
                            >
                              {examiner.is_active ? "Active" : "Inactive"}
                            </span>
                          </td>
                          <td className="py-3 text-right">
                            <div className="inline-flex flex-wrap justify-end gap-2">
                              <button
                                type="button"
                                disabled={busyExaminerId === examiner.id}
                                aria-label={`${examiner.is_active ? "Deactivate" : "Activate"} ${examiner.username}`}
                                onClick={() => handleToggleActive(examiner)}
                                className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                              >
                                {examiner.is_active ? "Deactivate" : "Activate"}
                              </button>
                              <button
                                type="button"
                                disabled={busyExaminerId === examiner.id}
                                aria-label={`Reset password for ${examiner.username}`}
                                onClick={() => handleResetPassword(examiner)}
                                className="rounded-md border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-50"
                              >
                                Reset password
                              </button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}

              {examinerMeta && examinerMeta.last_page > 1 ? (
                <div className="flex items-center justify-between pt-2 text-xs text-slate-600">
                  <span>
                    Page {examinerMeta.current_page} of {examinerMeta.last_page} ({examinerMeta.total} total)
                  </span>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      aria-label="Previous page of examiners"
                      disabled={examinerMeta.current_page <= 1}
                      onClick={() => loadExaminers(examinerMeta.current_page - 1, searchUsername, statusFilter)}
                      className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
                    >
                      Previous
                    </button>
                    <button
                      type="button"
                      aria-label="Next page of examiners"
                      disabled={examinerMeta.current_page >= examinerMeta.last_page}
                      onClick={() => loadExaminers(examinerMeta.current_page + 1, searchUsername, statusFilter)}
                      className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
                    >
                      Next
                    </button>
                  </div>
                </div>
              ) : null}
            </section>
          </div>
        ) : (
          <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <div>
                <h2 className="text-base font-semibold text-slate-900">Audit logs</h2>
                <p className="text-xs text-slate-600">
                  Append-only security and domain trail stored in PostgreSQL.
                </p>
              </div>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  setError(null);
                  loadAuditLogs(1, auditFilters);
                }}
                className="flex flex-wrap items-center gap-2"
              >
                <select
                  aria-label="Actor type filter"
                  value={auditFilters.actor_type}
                  onChange={(e) => setAuditFilters((f) => ({ ...f, actor_type: e.target.value }))}
                  className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm"
                >
                  <option value="">All actors</option>
                  <option value="admin">admin</option>
                  <option value="examiner">examiner</option>
                  <option value="student">student</option>
                  <option value="system">system</option>
                  <option value="anonymous">anonymous</option>
                </select>
                <input
                  aria-label="Event type filter"
                  type="text"
                  value={auditFilters.event_type}
                  onChange={(e) => setAuditFilters((f) => ({ ...f, event_type: e.target.value }))}
                  placeholder="event_type"
                  className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm"
                />
                <input
                  aria-label="Target type filter"
                  type="text"
                  value={auditFilters.target_type}
                  onChange={(e) => setAuditFilters((f) => ({ ...f, target_type: e.target.value }))}
                  placeholder="target_type"
                  className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm"
                />
                <input
                  aria-label="From timestamp filter"
                  type="datetime-local"
                  value={auditFilters.from}
                  onChange={(e) => setAuditFilters((f) => ({ ...f, from: e.target.value }))}
                  className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm"
                />
                <input
                  aria-label="To timestamp filter"
                  type="datetime-local"
                  value={auditFilters.to}
                  onChange={(e) => setAuditFilters((f) => ({ ...f, to: e.target.value }))}
                  className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm"
                />
                <button
                  type="submit"
                  className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700"
                >
                  Apply
                </button>
                <button
                  type="button"
                  onClick={() => {
                    const cleared = { actor_type: "", event_type: "", target_type: "", from: "", to: "" };
                    setAuditFilters(cleared);
                    setError(null);
                    loadAuditLogs(1, cleared);
                  }}
                  className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                  Reset
                </button>
              </form>
            </div>

            {auditLoading ? (
              <p role="status" className="text-sm text-slate-600">
                Loading audit logs...
              </p>
            ) : auditLogs.length === 0 ? (
              <p className="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">
                No audit events match the active filters.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200 text-left text-xs">
                  <thead className="font-semibold uppercase text-slate-500">
                    <tr>
                      <th className="py-2 pr-3">Time (UTC)</th>
                      <th className="py-2 pr-3">Event</th>
                      <th className="py-2 pr-3">Actor</th>
                      <th className="py-2 pr-3">Target</th>
                      <th className="py-2 pr-3">IP</th>
                      <th className="py-2">Metadata</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {auditLogs.map((log) => (
                      <tr key={log.id}>
                        <td className="whitespace-nowrap py-2.5 pr-3 font-mono text-slate-600">
                          {log.created_at}
                        </td>
                        <td className="whitespace-nowrap py-2.5 pr-3 font-mono font-medium text-slate-900">
                          {log.event_type}
                        </td>
                        <td className="py-2.5 pr-3">
                          <span className="font-mono text-slate-700">
                            {log.actor_type}
                            {log.actor_label ? ` (${log.actor_label})` : ""}
                          </span>
                        </td>
                        <td className="py-2.5 pr-3 font-mono text-slate-600">
                          {log.target_type ? `${log.target_type}:${log.target_id ?? "—"}` : "—"}
                        </td>
                        <td className="py-2.5 pr-3 font-mono text-slate-600">{log.ip_address || "—"}</td>
                        <td className="py-2.5 font-mono text-slate-600">
                          {JSON.stringify(log.metadata ?? {})}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {auditMeta && auditMeta.last_page > 1 ? (
              <div className="flex items-center justify-between pt-2 text-xs text-slate-600">
                <span>
                  Page {auditMeta.current_page} of {auditMeta.last_page} ({auditMeta.total} total)
                </span>
                <div className="flex gap-2">
                  <button
                    type="button"
                    disabled={auditMeta.current_page <= 1}
                    onClick={() => loadAuditLogs(auditMeta.current_page - 1, auditFilters)}
                    className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    disabled={auditMeta.current_page >= auditMeta.last_page}
                    onClick={() => loadAuditLogs(auditMeta.current_page + 1, auditFilters)}
                    className="rounded border border-slate-300 px-2.5 py-1 disabled:opacity-40"
                  >
                    Next
                  </button>
                </div>
              </div>
            ) : null}
          </section>
        )}
      </main>
    </div>
  );
}
