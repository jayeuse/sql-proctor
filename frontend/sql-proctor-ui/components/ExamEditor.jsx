"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { api, ensureCsrfCookie, extractApiError } from "../lib/api";
import ExaminerShell from "./ExaminerShell";
import SchemaSummaryPanel from "./SchemaSummaryPanel";
import SqlEditor from "./SqlEditor";

function localDateTimeToIso(value) {
  const trimmed = value ? String(value).trim() : "";
  if (!trimmed) {
    return null;
  }
  const parsed = new Date(trimmed);
  return !Number.isNaN(parsed.getTime()) ? parsed.toISOString() : trimmed;
}

function formatLocalWindow(fromIso, untilIso) {
  if (!fromIso && !untilIso) {
    return "Open immediately (no time window)";
  }
  const fmt = (iso) => {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString();
  };
  if (fromIso && untilIso) {
    return `Window: ${fmt(fromIso)} to ${fmt(untilIso)}`;
  }
  if (fromIso) {
    return `Opens: ${fmt(fromIso)}`;
  }
  return `Closes: ${fmt(untilIso)}`;
}

function blankQuestion(type = "sql_select") {
  const isSql = type.startsWith("sql_");
  return {
    id: null,
    question_type: type,
    grading_mode: type === "short_answer" ? "manual" : "auto",
    title: "",
    prompt: "",
    points: "10",
    starter_sql: isSql ? "" : "",
    reference_sql: isSql ? "" : "",
    order_sensitive: false,
    column_name_sensitive: true,
    case_sensitive_values: true,
    float_tolerance: "0.001",
    required_clauses: "",
    forbidden_patterns: "",
    test_cases: isSql
      ? [
          {
            name: "Standard dataset",
            weight_points: "10",
            is_hidden: false,
            expected_behavior: "match_reference",
            override_seed_sql: "",
            assertion_sql: "",
          },
        ]
      : [],
    options:
      type === "mcq"
        ? [
            { label: "", is_correct: true },
            { label: "", is_correct: false },
          ]
        : [],
    rubric: [],
  };
}

function questionToForm(q) {
  const cfg = q.comparison_config ?? {};
  return {
    id: q.id,
    question_type: q.question_type,
    grading_mode: q.grading_mode,
    title: q.title ?? "",
    prompt: q.prompt ?? "",
    points: String(q.points ?? "10"),
    starter_sql: q.starter_sql ?? "",
    reference_sql: q.reference_sql ?? "",
    order_sensitive: Boolean(cfg.order_sensitive),
    column_name_sensitive: cfg.column_name_sensitive ?? true,
    case_sensitive_values: cfg.case_sensitive_values ?? true,
    float_tolerance: String(cfg.float_tolerance ?? "0.001"),
    required_clauses: Array.isArray(cfg.required_clauses) ? cfg.required_clauses.join(", ") : "",
    forbidden_patterns: Array.isArray(cfg.forbidden_patterns) ? cfg.forbidden_patterns.join(", ") : "",
    test_cases: (q.test_cases ?? []).map((tc) => ({
      name: tc.name ?? "",
      weight_points: String(tc.weight_points ?? "0"),
      is_hidden: Boolean(tc.is_hidden),
      expected_behavior: tc.expected_behavior ?? "match_reference",
      override_seed_sql: tc.override_seed_sql ?? "",
      assertion_sql: tc.assertion_sql ?? "",
    })),
    options: (q.options ?? []).map((opt) => ({
      label: opt.label ?? "",
      is_correct: Boolean(opt.is_correct),
    })),
    rubric: (q.rubric ?? []).map((item) => ({
      id: item.id ?? "criterion",
      label: item.label ?? "",
      max_points: String(item.max_points ?? "0"),
      description: item.description ?? "",
    })),
  };
}

export default function ExamEditor({ examId: examIdProp }) {
  const params = useParams();
  const router = useRouter();
  const routerRef = useRef(router);
  useEffect(() => {
    routerRef.current = router;
  }, [router]);
  const examId = examIdProp ?? params?.examId;

  const [currentUser, setCurrentUser] = useState(null);
  const [exam, setExam] = useState(null);
  const [datasets, setDatasets] = useState([]);
  const [classrooms, setClassrooms] = useState([]);
  const [activeTab, setActiveTab] = useState("settings");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [validationIssues, setValidationIssues] = useState([]);
  const [notice, setNotice] = useState("");

  const [settingsForm, setSettingsForm] = useState({
    title: "",
    description: "",
    instructions: "",
    dataset_id: "",
    duration_minutes: 60,
    passing_score_percent: 60,
    allow_query_preview: true,
    shuffle_questions: false,
    show_points: true,
    immediate_auto_results: false,
    max_query_runs_per_question: "",
  });

  const [questionForm, setQuestionForm] = useState(blankQuestion());
  const [showUnpublishConfirm, setShowUnpublishConfirm] = useState(false);
  const [forceCloseActive, setForceCloseActive] = useState(false);
  const [assignForm, setAssignForm] = useState({
    classroom_id: "",
    available_from: "",
    available_until: "",
  });

  const syncSettingsFromExam = useCallback((loaded) => {
    const s = loaded.settings ?? {};
    setSettingsForm({
      title: loaded.title ?? "",
      description: loaded.description ?? "",
      instructions: loaded.instructions ?? "",
      dataset_id: loaded.dataset_id ?? "",
      duration_minutes: loaded.duration_minutes ?? 60,
      passing_score_percent: Number(loaded.passing_score_percent ?? 60),
      allow_query_preview: s.allow_query_preview ?? true,
      shuffle_questions: Boolean(s.shuffle_questions),
      show_points: s.show_points ?? true,
      immediate_auto_results: Boolean(s.immediate_auto_results),
      max_query_runs_per_question:
        s.max_query_runs_per_question === null || s.max_query_runs_per_question === undefined
          ? ""
          : String(s.max_query_runs_per_question),
    });
  }, []);

  const refreshExam = useCallback(async () => {
    if (!examId) {
      return;
    }
    const res = await api.get(`/examiner/exams/${examId}`);
    const loaded = res.data?.data;
    if (loaded) {
      setExam(loaded);
      syncSettingsFromExam(loaded);
    }
  }, [examId, syncSettingsFromExam]);

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

        const [examRes, dsRes, clsRes] = await Promise.all([
          api.get(`/examiner/exams/${examId}`),
          api.get("/examiner/datasets", { params: { scope: "all", per_page: 50 } }),
          api.get("/examiner/classes", { params: { per_page: 50 } }),
        ]);

        if (!active) {
          return;
        }
        const loadedExam = examRes.data?.data;
        if (loadedExam) {
          setExam(loadedExam);
          syncSettingsFromExam(loadedExam);
        }
        setDatasets(dsRes.data?.data ?? []);
        setClassrooms(clsRes.data?.data ?? []);
      } catch (err) {
        if (!active) {
          return;
        }
        const apiErr = extractApiError(err, "Failed to load exam builder.");
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
  }, [examId, syncSettingsFromExam]);

  const isEditable = Boolean(exam?.is_editable);
  const isSqlType = questionForm.question_type.startsWith("sql_");

  const testWeightSum = useMemo(
    () =>
      questionForm.test_cases.reduce((sum, tc) => sum + (Number.parseFloat(tc.weight_points) || 0), 0),
    [questionForm.test_cases],
  );
  const questionPointsNum = Number.parseFloat(questionForm.points) || 0;
  const weightMismatch =
    isSqlType &&
    questionForm.test_cases.length > 0 &&
    Math.abs(testWeightSum - questionPointsNum) > 0.001;

  function clearFeedback() {
    setError("");
    setValidationIssues([]);
    setNotice("");
  }

  async function handleSaveSettings(e) {
    e.preventDefault();
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.patch(`/examiner/exams/${examId}`, {
        title: settingsForm.title,
        description: settingsForm.description || null,
        instructions: settingsForm.instructions || null,
        dataset_id: settingsForm.dataset_id || null,
        duration_minutes: Number(settingsForm.duration_minutes) || 60,
        passing_score_percent: Number(settingsForm.passing_score_percent) || 0,
        settings: {
          allow_query_preview: Boolean(settingsForm.allow_query_preview),
          shuffle_questions: Boolean(settingsForm.shuffle_questions),
          show_points: Boolean(settingsForm.show_points),
          immediate_auto_results: Boolean(settingsForm.immediate_auto_results),
          max_query_runs_per_question:
            settingsForm.max_query_runs_per_question === ""
              ? null
              : Number(settingsForm.max_query_runs_per_question),
        },
      });
      await refreshExam();
      setNotice("Exam settings saved.");
    } catch (err) {
      setError(extractApiError(err, "Failed to update settings.").message);
    } finally {
      setSaving(false);
    }
  }

  function handleQuestionTypeChange(nextType) {
    const nextIsSql = nextType.startsWith("sql_");
    setQuestionForm((prev) => ({
      ...prev,
      question_type: nextType,
      grading_mode: prev.id ? prev.grading_mode : nextType === "short_answer" ? "manual" : "auto",
      test_cases:
        nextIsSql && prev.test_cases.length === 0
          ? [
              {
                name: "Standard dataset",
                weight_points: prev.points || "10",
                is_hidden: false,
                expected_behavior: "match_reference",
                override_seed_sql: "",
                assertion_sql: "",
              },
            ]
          : nextIsSql
            ? prev.test_cases
            : [],
      options:
        nextType === "mcq" && prev.options.length === 0
          ? [
              { label: "", is_correct: true },
              { label: "", is_correct: false },
            ]
          : nextType === "mcq"
            ? prev.options
            : [],
    }));
  }

  async function handleSaveQuestion(e) {
    e.preventDefault();
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      const payload = {
        question_type: questionForm.question_type,
        grading_mode: questionForm.grading_mode,
        title: questionForm.title,
        prompt: questionForm.prompt,
        points: Number(questionForm.points) || 1,
      };

      if (isSqlType) {
        payload.starter_sql = questionForm.starter_sql || null;
        payload.reference_sql = questionForm.reference_sql || null;
        payload.comparison_config = {
          order_sensitive: Boolean(questionForm.order_sensitive),
          column_name_sensitive: Boolean(questionForm.column_name_sensitive),
          case_sensitive_values: Boolean(questionForm.case_sensitive_values),
          float_tolerance: Number(questionForm.float_tolerance) || 0,
          required_clauses: questionForm.required_clauses
            .split(",")
            .map((s) => s.trim())
            .filter(Boolean),
          forbidden_patterns: questionForm.forbidden_patterns
            .split(",")
            .map((s) => s.trim())
            .filter(Boolean),
        };
        payload.test_cases = questionForm.test_cases.map((tc) => ({
          name: tc.name,
          weight_points: Number(tc.weight_points) || 0,
          is_hidden: Boolean(tc.is_hidden),
          expected_behavior: tc.expected_behavior,
          override_seed_sql: tc.override_seed_sql || null,
          assertion_sql: tc.assertion_sql || null,
        }));
      } else {
        payload.test_cases = [];
      }

      if (questionForm.question_type === "mcq") {
        payload.options = questionForm.options.map((opt) => ({
          label: opt.label,
          is_correct: Boolean(opt.is_correct),
        }));
      } else {
        payload.options = [];
      }

      if (questionForm.grading_mode === "manual" || questionForm.grading_mode === "hybrid") {
        payload.rubric = questionForm.rubric.map((r, idx) => ({
          id: r.id || `crit_${idx + 1}`,
          label: r.label,
          max_points: Number(r.max_points) || 0,
          description: r.description || null,
        }));
      } else {
        payload.rubric = [];
      }

      if (questionForm.id) {
        await api.patch(`/examiner/exams/${examId}/questions/${questionForm.id}`, payload);
        setNotice("Question updated.");
      } else {
        await api.post(`/examiner/exams/${examId}/questions`, payload);
        setNotice("Question added.");
      }

      await refreshExam();
      setQuestionForm(blankQuestion());
    } catch (err) {
      setError(extractApiError(err, "Failed to save question.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDeleteQuestion(questionId) {
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.delete(`/examiner/exams/${examId}/questions/${questionId}`);
      if (questionForm.id === questionId) {
        setQuestionForm(blankQuestion());
      }
      await refreshExam();
      setNotice("Question deleted.");
    } catch (err) {
      setError(extractApiError(err, "Failed to delete question.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleMoveQuestion(index, direction) {
    const list = exam?.questions ?? [];
    const targetIndex = index + direction;
    if (targetIndex < 0 || targetIndex >= list.length) {
      return;
    }
    const nextIds = list.map((q) => q.id);
    const [moved] = nextIds.splice(index, 1);
    nextIds.splice(targetIndex, 0, moved);

    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.put(`/examiner/exams/${examId}/questions/reorder`, { question_ids: nextIds });
      await refreshExam();
      setNotice("Question order updated.");
    } catch (err) {
      setError(extractApiError(err, "Failed to reorder questions.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleValidateExam() {
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.post(`/examiner/exams/${examId}/validate`);
      setNotice("Validation passed: ready to publish.");
    } catch (err) {
      const apiErr = extractApiError(err, "Exam validation failed.");
      const issues = err?.response?.data?.issues;
      if (Array.isArray(issues) && issues.length > 0) {
        setValidationIssues(issues);
      }
      setError(apiErr.message);
    } finally {
      setSaving(false);
    }
  }

  async function handlePublishExam() {
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.post(`/examiner/exams/${examId}/publish`);
      await refreshExam();
      setNotice("Exam published and immutable version snapshot created.");
    } catch (err) {
      const apiErr = extractApiError(err, "Failed to publish exam.");
      const issues = err?.response?.data?.issues;
      if (Array.isArray(issues) && issues.length > 0) {
        setValidationIssues(issues);
      }
      setError(apiErr.message);
    } finally {
      setSaving(false);
    }
  }

  async function handleUnpublishExam() {
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.post(`/examiner/exams/${examId}/unpublish`, {
        force_close_active: forceCloseActive,
      });
      setShowUnpublishConfirm(false);
      await refreshExam();
      setNotice("Exam unpublished.");
    } catch (err) {
      setError(extractApiError(err, "Failed to unpublish exam.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleArchiveExam() {
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.post(`/examiner/exams/${examId}/archive`);
      await refreshExam();
      setNotice("Exam archived.");
    } catch (err) {
      setError(extractApiError(err, "Failed to archive exam.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleCloneExam() {
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      const res = await api.post(`/examiner/exams/${examId}/clone`);
      const cloned = res.data?.data;
      if (cloned?.id) {
        router.push(`/examiner/exams/${cloned.id}`);
      }
    } catch (err) {
      setError(extractApiError(err, "Failed to clone exam.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleAssignClassroom(e) {
    e.preventDefault();
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.post(`/examiner/exams/${examId}/classrooms`, {
        classroom_id: assignForm.classroom_id,
        available_from: localDateTimeToIso(assignForm.available_from),
        available_until: localDateTimeToIso(assignForm.available_until),
      });
      setAssignForm({ classroom_id: "", available_from: "", available_until: "" });
      await refreshExam();
      setNotice("Exam assigned to classroom.");
    } catch (err) {
      setError(extractApiError(err, "Failed to assign classroom.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleUpdateAssignment(classroomId, body, okMsg) {
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.patch(`/examiner/exams/${examId}/classrooms/${classroomId}`, body);
      await refreshExam();
      setNotice(okMsg);
    } catch (err) {
      setError(extractApiError(err, "Failed to update classroom assignment.").message);
    } finally {
      setSaving(false);
    }
  }

  async function handleUnassignClassroom(classroomId) {
    clearFeedback();
    setSaving(true);
    try {
      await ensureCsrfCookie();
      await api.delete(`/examiner/exams/${examId}/classrooms/${classroomId}`);
      await refreshExam();
      setNotice("Classroom assignment removed.");
    } catch (err) {
      setError(extractApiError(err, "Failed to unassign classroom.").message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <ExaminerShell title={exam ? exam.title : "Exam Builder"} activeSection="exams" currentUser={currentUser}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <Link href="/examiner/exams" className="font-medium text-indigo-600 hover:text-indigo-700">
            Back to Exams
          </Link>
          {exam ? (
            <>
              <span className="text-slate-400">/</span>
              <span className="rounded-full bg-slate-200 px-2.5 py-0.5 font-medium capitalize text-slate-800">
                {exam.status}
              </span>
              {exam.current_version_number > 0 ? (
                <span className="rounded bg-indigo-50 px-2 py-0.5 font-mono font-semibold text-indigo-700">
                  v{exam.current_version_number}
                </span>
              ) : null}
              <span className="text-slate-600">{exam.total_points} pts</span>
            </>
          ) : null}
        </div>
        {exam ? (
          <div className="flex flex-wrap gap-2">
            <Link
              href={`/examiner/exams/${exam.id}/attempts`}
              className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
            >
              Attempts &amp; Grading
            </Link>
            <button
              type="button"
              disabled={saving}
              onClick={handleCloneExam}
              className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Clone Draft
            </button>
          </div>
        ) : null}
      </div>

      {exam && !isEditable ? (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          This exam is locked ({exam.status}). Clone it to create a new editable draft.
        </div>
      ) : null}

      {error ? (
        <div role="alert" className="space-y-1 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          <p className="font-medium">{error}</p>
          {validationIssues.length > 0 ? (
            <ul className="list-disc pl-5 text-xs">
              {validationIssues.map((issue, idx) => (
                <li key={idx}>{issue}</li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}

      {notice ? (
        <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          {notice}
        </div>
      ) : null}

      {loading ? (
        <p className="text-sm text-slate-600">Loading exam builder...</p>
      ) : exam ? (
        <div className="space-y-6">
          <div role="tablist" aria-label="Exam builder sections" className="flex flex-wrap gap-2 border-b border-slate-200">
            {[
              { id: "settings", label: "Settings" },
              { id: "questions", label: `Questions (${exam.questions?.length ?? 0})` },
              { id: "publish", label: "Validate & Publish" },
              { id: "classrooms", label: `Classrooms (${exam.classroom_exams?.length ?? 0})` },
            ].map((tab) => (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={activeTab === tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                  activeTab === tab.id
                    ? "border-indigo-600 text-indigo-700"
                    : "border-transparent text-slate-600 hover:text-slate-900"
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>

          {activeTab === "settings" ? (
            <section className="grid grid-cols-1 gap-6 lg:grid-cols-12">
              <form
                onSubmit={handleSaveSettings}
                className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-8"
              >
                <h2 className="text-base font-semibold text-slate-900">Exam Settings</h2>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div>
                    <label htmlFor="edit-title" className="block text-sm font-medium text-slate-700">
                      Title
                    </label>
                    <input
                      id="edit-title"
                      type="text"
                      disabled={!isEditable}
                      value={settingsForm.title}
                      onChange={(e) => setSettingsForm((p) => ({ ...p, title: e.target.value }))}
                      className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                    />
                  </div>
                  <div>
                    <label htmlFor="edit-dataset" className="block text-sm font-medium text-slate-700">
                      SQL Dataset
                    </label>
                    <select
                      id="edit-dataset"
                      disabled={!isEditable}
                      value={settingsForm.dataset_id}
                      onChange={(e) => setSettingsForm((p) => ({ ...p, dataset_id: e.target.value }))}
                      className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                    >
                      <option value="">None</option>
                      {datasets.map((ds) => (
                        <option key={ds.id} value={ds.id}>
                          {ds.name} {ds.validated_at ? "(Validated)" : "(Unvalidated)"}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label htmlFor="edit-duration" className="block text-sm font-medium text-slate-700">
                      Duration (minutes)
                    </label>
                    <input
                      id="edit-duration"
                      type="number"
                      min={1}
                      max={480}
                      disabled={!isEditable}
                      value={settingsForm.duration_minutes}
                      onChange={(e) => setSettingsForm((p) => ({ ...p, duration_minutes: e.target.value }))}
                      className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                    />
                  </div>
                  <div>
                    <label htmlFor="edit-passing" className="block text-sm font-medium text-slate-700">
                      Passing Score (%)
                    </label>
                    <input
                      id="edit-passing"
                      type="number"
                      min={0}
                      max={100}
                      disabled={!isEditable}
                      value={settingsForm.passing_score_percent}
                      onChange={(e) => setSettingsForm((p) => ({ ...p, passing_score_percent: e.target.value }))}
                      className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                    />
                  </div>
                </div>

                <div>
                  <label htmlFor="edit-description" className="block text-sm font-medium text-slate-700">
                    Description
                  </label>
                  <textarea
                    id="edit-description"
                    rows={2}
                    disabled={!isEditable}
                    value={settingsForm.description}
                    onChange={(e) => setSettingsForm((p) => ({ ...p, description: e.target.value }))}
                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                  />
                </div>

                <div>
                  <label htmlFor="edit-instructions" className="block text-sm font-medium text-slate-700">
                    Instructions
                  </label>
                  <textarea
                    id="edit-instructions"
                    rows={3}
                    disabled={!isEditable}
                    value={settingsForm.instructions}
                    onChange={(e) => setSettingsForm((p) => ({ ...p, instructions: e.target.value }))}
                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                  />
                </div>

                <div className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                  <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      disabled={!isEditable}
                      checked={settingsForm.allow_query_preview}
                      onChange={(e) => setSettingsForm((p) => ({ ...p, allow_query_preview: e.target.checked }))}
                    />
                    Allow student live query preview
                  </label>
                  <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      disabled={!isEditable}
                      checked={settingsForm.shuffle_questions}
                      onChange={(e) => setSettingsForm((p) => ({ ...p, shuffle_questions: e.target.checked }))}
                    />
                    Shuffle question order per attempt
                  </label>
                  <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      disabled={!isEditable}
                      checked={settingsForm.show_points}
                      onChange={(e) => setSettingsForm((p) => ({ ...p, show_points: e.target.checked }))}
                    />
                    Show question points to students
                  </label>
                  <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      disabled={!isEditable}
                      checked={settingsForm.immediate_auto_results}
                      onChange={(e) => setSettingsForm((p) => ({ ...p, immediate_auto_results: e.target.checked }))}
                    />
                    Immediate auto-graded result release
                  </label>
                  <div className="sm:col-span-2">
                    <label htmlFor="edit-max-runs" className="block text-xs font-medium text-slate-700">
                      Max Query Runs Per Question (optional)
                    </label>
                    <input
                      id="edit-max-runs"
                      type="number"
                      min={1}
                      max={1000}
                      disabled={!isEditable}
                      value={settingsForm.max_query_runs_per_question}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, max_query_runs_per_question: e.target.value }))
                      }
                      placeholder="Unlimited"
                      className="mt-1 w-40 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-900 disabled:bg-slate-100"
                    />
                  </div>
                </div>

                <button
                  type="submit"
                  disabled={!isEditable || saving}
                  className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                  Save Settings
                </button>
              </form>

              <aside className="space-y-3 rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-4">
                <h2 className="text-base font-semibold text-slate-900">Attached Dataset Schema</h2>
                <SchemaSummaryPanel
                  schemaSummary={exam.dataset?.schema_summary}
                  emptyText="Attach a MariaDB-validated dataset in Settings to inspect its schema."
                />
              </aside>
            </section>
          ) : null}

          {activeTab === "questions" ? (
            <section className="grid grid-cols-1 gap-6 lg:grid-cols-12">
              <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-5">
                <div className="flex items-center justify-between gap-2">
                  <h2 className="text-base font-semibold text-slate-900">Questions</h2>
                  <button
                    type="button"
                    disabled={!isEditable}
                    onClick={() => setQuestionForm(blankQuestion())}
                    className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                  >
                    New Question
                  </button>
                </div>

                {(exam.questions ?? []).length === 0 ? (
                  <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-600">
                    No questions authored yet.
                  </p>
                ) : (
                  <ul className="space-y-2">
                    {exam.questions.map((q, idx) => (
                      <li
                        key={q.id}
                        className={`rounded-lg border p-3 ${
                          questionForm.id === q.id ? "border-indigo-500 bg-indigo-50/40" : "border-slate-200"
                        }`}
                      >
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0">
                            <p className="text-sm font-medium text-slate-900">
                              <span>Q{idx + 1}. </span>
                              <span>{q.title}</span>
                            </p>
                            <p className="text-xs text-slate-600">
                              {q.question_type} · {q.grading_mode} · {q.points} pts
                            </p>
                          </div>
                          {isEditable ? (
                            <div className="flex flex-wrap gap-1">
                              <button
                                type="button"
                                disabled={idx === 0 || saving}
                                onClick={() => handleMoveQuestion(idx, -1)}
                                className="rounded border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-40"
                              >
                                Up
                              </button>
                              <button
                                type="button"
                                disabled={idx === exam.questions.length - 1 || saving}
                                onClick={() => handleMoveQuestion(idx, 1)}
                                className="rounded border border-slate-300 px-2 py-0.5 text-xs disabled:opacity-40"
                              >
                                Down
                              </button>
                              <button
                                type="button"
                                onClick={() => setQuestionForm(questionToForm(q))}
                                className="rounded border border-slate-300 px-2 py-0.5 text-xs"
                              >
                                Edit
                              </button>
                              <button
                                type="button"
                                disabled={saving}
                                onClick={() => handleDeleteQuestion(q.id)}
                                className="rounded border border-rose-300 px-2 py-0.5 text-xs text-rose-700"
                              >
                                Delete
                              </button>
                            </div>
                          ) : (
                            <button
                              type="button"
                              onClick={() => setQuestionForm(questionToForm(q))}
                              className="rounded border border-slate-300 px-2 py-0.5 text-xs"
                            >
                              View
                            </button>
                          )}
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </div>

              <form
                onSubmit={handleSaveQuestion}
                className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-7"
              >
                <h2 className="text-base font-semibold text-slate-900">
                  {questionForm.id ? "Edit Question" : "Add Question"}
                </h2>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <div>
                    <label htmlFor="q-type" className="block text-sm font-medium text-slate-700">
                      Question Type
                    </label>
                    <select
                      id="q-type"
                      disabled={!isEditable}
                      value={questionForm.question_type}
                      onChange={(e) => handleQuestionTypeChange(e.target.value)}
                      className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                    >
                      <option value="sql_select">SQL SELECT</option>
                      <option value="sql_dml">SQL DML</option>
                      <option value="sql_ddl">SQL DDL</option>
                      <option value="sql_debug">SQL Debug</option>
                      <option value="short_answer">Short Answer</option>
                      <option value="mcq">Multiple Choice (MCQ)</option>
                    </select>
                  </div>
                  <div>
                    <label htmlFor="q-mode" className="block text-sm font-medium text-slate-700">
                      Grading Mode
                    </label>
                    <select
                      id="q-mode"
                      disabled={!isEditable}
                      value={questionForm.grading_mode}
                      onChange={(e) => setQuestionForm((p) => ({ ...p, grading_mode: e.target.value }))}
                      className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                    >
                      <option value="auto">Auto</option>
                      <option value="manual">Manual</option>
                      <option value="hybrid">Hybrid</option>
                    </select>
                  </div>
                  <div>
                    <label htmlFor="q-points" className="block text-sm font-medium text-slate-700">
                      Points
                    </label>
                    <input
                      id="q-points"
                      type="number"
                      step="0.5"
                      min="0.5"
                      disabled={!isEditable}
                      value={questionForm.points}
                      onChange={(e) => setQuestionForm((p) => ({ ...p, points: e.target.value }))}
                      className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                    />
                  </div>
                </div>

                <div>
                  <label htmlFor="q-title" className="block text-sm font-medium text-slate-700">
                    Question Title
                  </label>
                  <input
                    id="q-title"
                    type="text"
                    disabled={!isEditable}
                    value={questionForm.title}
                    onChange={(e) => setQuestionForm((p) => ({ ...p, title: e.target.value }))}
                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                  />
                </div>

                <div>
                  <label htmlFor="q-prompt" className="block text-sm font-medium text-slate-700">
                    Prompt
                  </label>
                  <textarea
                    id="q-prompt"
                    rows={3}
                    disabled={!isEditable}
                    value={questionForm.prompt}
                    onChange={(e) => setQuestionForm((p) => ({ ...p, prompt: e.target.value }))}
                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 disabled:bg-slate-100"
                  />
                </div>

                {isSqlType ? (
                  <div className="space-y-4">
                    <SqlEditor
                      label="Starter SQL"
                      value={questionForm.starter_sql}
                      readOnly={!isEditable}
                      height={120}
                      onChange={(val) => setQuestionForm((p) => ({ ...p, starter_sql: val }))}
                    />
                    <SqlEditor
                      label="Reference SQL (Staff Only)"
                      value={questionForm.reference_sql}
                      readOnly={!isEditable}
                      height={140}
                      onChange={(val) => setQuestionForm((p) => ({ ...p, reference_sql: val }))}
                    />

                    <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                      <p className="text-xs font-semibold uppercase tracking-wide text-slate-700">
                        Result Comparison Options
                      </p>
                      <div className="flex flex-wrap gap-4 text-xs text-slate-700">
                        <label className="flex items-center gap-1.5">
                          <input
                            type="checkbox"
                            disabled={!isEditable}
                            checked={questionForm.order_sensitive}
                            onChange={(e) =>
                              setQuestionForm((p) => ({ ...p, order_sensitive: e.target.checked }))
                            }
                          />
                          Order sensitive
                        </label>
                        <label className="flex items-center gap-1.5">
                          <input
                            type="checkbox"
                            disabled={!isEditable}
                            checked={questionForm.column_name_sensitive}
                            onChange={(e) =>
                              setQuestionForm((p) => ({ ...p, column_name_sensitive: e.target.checked }))
                            }
                          />
                          Column names sensitive
                        </label>
                        <label className="flex items-center gap-1.5">
                          <input
                            type="checkbox"
                            disabled={!isEditable}
                            checked={questionForm.case_sensitive_values}
                            onChange={(e) =>
                              setQuestionForm((p) => ({ ...p, case_sensitive_values: e.target.checked }))
                            }
                          />
                          Case sensitive values
                        </label>
                      </div>
                      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <input
                          type="text"
                          disabled={!isEditable}
                          placeholder="Required SQL clauses (comma separated)"
                          value={questionForm.required_clauses}
                          onChange={(e) =>
                            setQuestionForm((p) => ({ ...p, required_clauses: e.target.value }))
                          }
                          className="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs"
                        />
                        <input
                          type="text"
                          disabled={!isEditable}
                          placeholder="Forbidden SQL patterns (comma separated)"
                          value={questionForm.forbidden_patterns}
                          onChange={(e) =>
                            setQuestionForm((p) => ({ ...p, forbidden_patterns: e.target.value }))
                          }
                          className="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs"
                        />
                      </div>
                    </div>

                    <div className="space-y-2 rounded-lg border border-slate-200 p-3">
                      <div className="flex items-center justify-between">
                        <span className="text-sm font-medium text-slate-900">
                          Test Cases (sum: {testWeightSum.toFixed(2)} / {questionPointsNum.toFixed(2)} pts)
                        </span>
                        {isEditable ? (
                          <button
                            type="button"
                            onClick={() =>
                              setQuestionForm((p) => ({
                                ...p,
                                test_cases: [
                                  ...p.test_cases,
                                  {
                                    name: `Case ${p.test_cases.length + 1}`,
                                    weight_points: "5",
                                    is_hidden: false,
                                    expected_behavior: "match_reference",
                                    override_seed_sql: "",
                                    assertion_sql: "",
                                  },
                                ],
                              }))
                            }
                            className="rounded border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700"
                          >
                            Add Test Case
                          </button>
                        ) : null}
                      </div>
                      {weightMismatch ? (
                        <p className="text-xs font-medium text-amber-700">
                          Test case weights must sum to {questionPointsNum.toFixed(2)}.
                        </p>
                      ) : null}
                      {questionForm.test_cases.map((tc, idx) => (
                        <div key={idx} className="space-y-2 rounded border border-slate-200 bg-slate-50 p-2.5 text-xs">
                          <div className="grid grid-cols-1 gap-2 sm:grid-cols-4">
                            <input
                              type="text"
                              disabled={!isEditable}
                              placeholder="Test case name"
                              aria-label={`Test Case ${idx + 1} Name`}
                              value={tc.name}
                              onChange={(e) =>
                                setQuestionForm((p) => ({
                                  ...p,
                                  test_cases: p.test_cases.map((item, i) =>
                                    i === idx ? { ...item, name: e.target.value } : item,
                                  ),
                                }))
                              }
                              className="rounded border border-slate-300 bg-white px-2 py-1 sm:col-span-2"
                            />
                            <input
                              type="number"
                              step="0.5"
                              disabled={!isEditable}
                              aria-label={`Test Case ${idx + 1} Weight`}
                              value={tc.weight_points}
                              onChange={(e) =>
                                setQuestionForm((p) => ({
                                  ...p,
                                  test_cases: p.test_cases.map((item, i) =>
                                    i === idx ? { ...item, weight_points: e.target.value } : item,
                                  ),
                                }))
                              }
                              className="rounded border border-slate-300 bg-white px-2 py-1"
                            />
                            <select
                              disabled={!isEditable}
                              aria-label={`Test Case ${idx + 1} Behavior`}
                              value={tc.expected_behavior}
                              onChange={(e) =>
                                setQuestionForm((p) => ({
                                  ...p,
                                  test_cases: p.test_cases.map((item, i) =>
                                    i === idx ? { ...item, expected_behavior: e.target.value } : item,
                                  ),
                                }))
                              }
                              className="rounded border border-slate-300 bg-white px-2 py-1"
                            >
                              <option value="match_reference">Match reference</option>
                              <option value="non_empty">Non-empty result</option>
                              <option value="expect_error">Expect SQL error</option>
                            </select>
                          </div>
                          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <input
                              type="text"
                              disabled={!isEditable}
                              placeholder="Override seed SQL (optional)"
                              value={tc.override_seed_sql}
                              onChange={(e) =>
                                setQuestionForm((p) => ({
                                  ...p,
                                  test_cases: p.test_cases.map((item, i) =>
                                    i === idx ? { ...item, override_seed_sql: e.target.value } : item,
                                  ),
                                }))
                              }
                              className="rounded border border-slate-300 bg-white px-2 py-1 font-mono"
                            />
                            <input
                              type="text"
                              disabled={!isEditable}
                              placeholder="Post-state assertion SQL (DML/DDL)"
                              value={tc.assertion_sql}
                              onChange={(e) =>
                                setQuestionForm((p) => ({
                                  ...p,
                                  test_cases: p.test_cases.map((item, i) =>
                                    i === idx ? { ...item, assertion_sql: e.target.value } : item,
                                  ),
                                }))
                              }
                              className="rounded border border-slate-300 bg-white px-2 py-1 font-mono"
                            />
                          </div>
                          <div className="flex items-center justify-between">
                            <label className="flex items-center gap-1.5">
                              <input
                                type="checkbox"
                                disabled={!isEditable}
                                checked={tc.is_hidden}
                                onChange={(e) =>
                                  setQuestionForm((p) => ({
                                    ...p,
                                    test_cases: p.test_cases.map((item, i) =>
                                      i === idx ? { ...item, is_hidden: e.target.checked } : item,
                                    ),
                                  }))
                                }
                              />
                              Hidden test case
                            </label>
                            {isEditable ? (
                              <button
                                type="button"
                                onClick={() =>
                                  setQuestionForm((p) => ({
                                    ...p,
                                    test_cases: p.test_cases.filter((_, i) => i !== idx),
                                  }))
                                }
                                className="text-rose-700 hover:underline"
                              >
                                Remove
                              </button>
                            ) : null}
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                ) : null}

                {questionForm.question_type === "mcq" ? (
                  <div className="space-y-2 rounded-lg border border-slate-200 p-3">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium text-slate-900">MCQ Options</span>
                      {isEditable ? (
                        <button
                          type="button"
                          onClick={() =>
                            setQuestionForm((p) => ({
                              ...p,
                              options: [...p.options, { label: "", is_correct: false }],
                            }))
                          }
                          className="rounded border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700"
                        >
                          Add Option
                        </button>
                      ) : null}
                    </div>
                    {questionForm.options.map((opt, idx) => (
                      <div key={idx} className="flex flex-wrap items-center gap-2">
                        <input
                          type="text"
                          disabled={!isEditable}
                          aria-label={`Option ${idx + 1} Label`}
                          placeholder={`Option ${idx + 1}`}
                          value={opt.label}
                          onChange={(e) =>
                            setQuestionForm((p) => ({
                              ...p,
                              options: p.options.map((item, i) =>
                                i === idx ? { ...item, label: e.target.value } : item,
                              ),
                            }))
                          }
                          className="min-w-0 flex-1 rounded border border-slate-300 px-2.5 py-1.5 text-sm"
                        />
                        <label className="flex items-center gap-1 text-xs text-slate-700">
                          <input
                            type="checkbox"
                            disabled={!isEditable}
                            aria-label={`Option ${idx + 1} Correct`}
                            checked={opt.is_correct}
                            onChange={(e) =>
                              setQuestionForm((p) => ({
                                ...p,
                                options: p.options.map((item, i) =>
                                  i === idx ? { ...item, is_correct: e.target.checked } : item,
                                ),
                              }))
                            }
                          />
                          Correct
                        </label>
                      </div>
                    ))}
                  </div>
                ) : null}

                {questionForm.grading_mode === "manual" || questionForm.grading_mode === "hybrid" ? (
                  <div className="space-y-2 rounded-lg border border-slate-200 p-3">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-medium text-slate-900">Rubric Criteria (optional)</span>
                      {isEditable ? (
                        <button
                          type="button"
                          onClick={() =>
                            setQuestionForm((p) => ({
                              ...p,
                              rubric: [
                                ...p.rubric,
                                {
                                  id: `r_${p.rubric.length + 1}`,
                                  label: "",
                                  max_points: p.points || "5",
                                  description: "",
                                },
                              ],
                            }))
                          }
                          className="rounded border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700"
                        >
                          Add Criterion
                        </button>
                      ) : null}
                    </div>
                    {questionForm.rubric.map((r, idx) => (
                      <div key={idx} className="grid grid-cols-1 gap-2 sm:grid-cols-4">
                        <input
                          type="text"
                          disabled={!isEditable}
                          placeholder="Criterion label"
                          value={r.label}
                          onChange={(e) =>
                            setQuestionForm((p) => ({
                              ...p,
                              rubric: p.rubric.map((item, i) =>
                                i === idx ? { ...item, label: e.target.value } : item,
                              ),
                            }))
                          }
                          className="rounded border border-slate-300 px-2 py-1 text-xs sm:col-span-2"
                        />
                        <input
                          type="number"
                          step="0.5"
                          disabled={!isEditable}
                          placeholder="Max pts"
                          value={r.max_points}
                          onChange={(e) =>
                            setQuestionForm((p) => ({
                              ...p,
                              rubric: p.rubric.map((item, i) =>
                                i === idx ? { ...item, max_points: e.target.value } : item,
                              ),
                            }))
                          }
                          className="rounded border border-slate-300 px-2 py-1 text-xs"
                        />
                        {isEditable ? (
                          <button
                            type="button"
                            onClick={() =>
                              setQuestionForm((p) => ({
                                ...p,
                                rubric: p.rubric.filter((_, i) => i !== idx),
                              }))
                            }
                            className="text-xs text-rose-700"
                          >
                            Remove
                          </button>
                        ) : null}
                      </div>
                    ))}
                  </div>
                ) : null}

                <button
                  type="submit"
                  disabled={!isEditable || saving}
                  className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                  Save Question
                </button>
              </form>
            </section>
          ) : null}

          {activeTab === "publish" ? (
            <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
              <h2 className="text-base font-semibold text-slate-900">Pre-Publish Validation &amp; Lifecycle</h2>
              <p className="text-sm text-slate-600">
                Publishing verifies dataset connectivity, executes every SQL reference answer in MariaDB 11.8, and saves
                an immutable version snapshot.
              </p>

              <div className="flex flex-wrap gap-3">
                <button
                  type="button"
                  disabled={saving}
                  onClick={handleValidateExam}
                  className="rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100 disabled:opacity-50"
                >
                  Run Validation
                </button>
                {exam.status !== "archived" ? (
                  <button
                    type="button"
                    disabled={saving}
                    onClick={handlePublishExam}
                    className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                  >
                    Publish Exam
                  </button>
                ) : null}
                {exam.status === "published" ? (
                  <button
                    type="button"
                    disabled={saving}
                    onClick={() => setShowUnpublishConfirm((prev) => !prev)}
                    className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100"
                  >
                    Unpublish Exam
                  </button>
                ) : null}
                {exam.status === "published" || exam.status === "unpublished" ? (
                  <button
                    type="button"
                    disabled={saving}
                    onClick={handleArchiveExam}
                    className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                  >
                    Archive Exam
                  </button>
                ) : null}
              </div>

              {showUnpublishConfirm ? (
                <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
                  <p className="text-sm font-medium text-amber-900">
                    Unpublishing stops new student attempts. Choose how to handle currently active attempts:
                  </p>
                  <label className="flex items-center gap-2 text-sm text-amber-900">
                    <input
                      type="checkbox"
                      checked={forceCloseActive}
                      onChange={(e) => setForceCloseActive(e.target.checked)}
                    />
                    Force-close and auto-grade active in-progress attempts
                  </label>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      disabled={saving}
                      onClick={handleUnpublishExam}
                      className="rounded-lg bg-amber-700 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-amber-800"
                    >
                      Confirm Unpublish
                    </button>
                    <button
                      type="button"
                      onClick={() => setShowUnpublishConfirm(false)}
                      className="rounded-lg border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700"
                    >
                      Cancel
                    </button>
                  </div>
                </div>
              ) : null}
            </section>
          ) : null}

          {activeTab === "classrooms" ? (
            <section className="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
              <h2 className="text-base font-semibold text-slate-900">Classroom Assignments &amp; Result Release</h2>

              <form onSubmit={handleAssignClassroom} className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <div>
                  <label htmlFor="assign-classroom" className="block text-xs font-medium text-slate-700">
                    Classroom
                  </label>
                  <select
                    id="assign-classroom"
                    value={assignForm.classroom_id}
                    onChange={(e) => setAssignForm((p) => ({ ...p, classroom_id: e.target.value }))}
                    className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900"
                  >
                    <option value="">Select classroom...</option>
                    {classrooms.map((cls) => (
                      <option key={cls.id} value={cls.id}>
                        {cls.name} ({cls.join_code})
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label htmlFor="assign-from" className="block text-xs font-medium text-slate-700">
                    Available From (optional)
                  </label>
                  <input
                    id="assign-from"
                    type="datetime-local"
                    value={assignForm.available_from}
                    onChange={(e) => setAssignForm((p) => ({ ...p, available_from: e.target.value }))}
                    className="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-xs text-slate-900"
                  />
                </div>
                <div>
                  <label htmlFor="assign-until" className="block text-xs font-medium text-slate-700">
                    Available Until (optional)
                  </label>
                  <input
                    id="assign-until"
                    type="datetime-local"
                    value={assignForm.available_until}
                    onChange={(e) => setAssignForm((p) => ({ ...p, available_until: e.target.value }))}
                    className="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-xs text-slate-900"
                  />
                </div>
                <div className="flex items-end">
                  <button
                    type="submit"
                    disabled={saving || !assignForm.classroom_id}
                    className="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                  >
                    Assign to Classroom
                  </button>
                </div>
              </form>

              {(exam.classroom_exams ?? []).length === 0 ? (
                <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-600">
                  No classrooms assigned yet. Publish the exam before assigning it.
                </p>
              ) : (
                <div className="space-y-3">
                  {exam.classroom_exams.map((ce) => (
                    <div
                      key={ce.id}
                      className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-4"
                    >
                      <div>
                        <p className="text-sm font-semibold text-slate-900">{ce.classroom?.name}</p>
                        <p className="text-xs text-slate-600">
                          Code <span className="font-mono">{ce.classroom?.join_code}</span> · v
                          {ce.version_number ?? exam.current_version_number} · {ce.attempts_count ?? 0} attempts ·{" "}
                          {ce.is_active ? "Active" : "Paused"}
                        </p>
                        <p className="mt-0.5 text-xs text-slate-500">
                          {formatLocalWindow(ce.available_from, ce.available_until)}
                        </p>
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        {(ce.available_from || ce.available_until) ? (
                          <button
                            type="button"
                            disabled={saving}
                            onClick={() =>
                              handleUpdateAssignment(
                                ce.classroom_id,
                                { available_from: null, available_until: null },
                                "Availability window cleared — exam is open immediately.",
                              )
                            }
                            className="rounded border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                          >
                            Clear Window
                          </button>
                        ) : null}
                        <button
                          type="button"
                          disabled={saving}
                          onClick={() =>
                            handleUpdateAssignment(
                              ce.classroom_id,
                              { is_active: !ce.is_active },
                              ce.is_active ? "Classroom assignment paused." : "Classroom assignment activated.",
                            )
                          }
                          className="rounded border border-slate-300 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
                        >
                          {ce.is_active ? "Pause" : "Activate"}
                        </button>
                        {ce.results_released_at ? (
                          <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
                            Results Released
                          </span>
                        ) : (
                          <button
                            type="button"
                            disabled={saving}
                            onClick={() =>
                              handleUpdateAssignment(
                                ce.classroom_id,
                                { release_results: true },
                                "Results released to classroom students.",
                              )
                            }
                            className="rounded bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-700"
                          >
                            Release Results
                          </button>
                        )}
                        <button
                          type="button"
                          disabled={saving}
                          onClick={() => handleUnassignClassroom(ce.classroom_id)}
                          className="rounded border border-rose-300 px-3 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50"
                        >
                          Unassign
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </section>
          ) : null}
        </div>
      ) : null}
    </ExaminerShell>
  );
}
