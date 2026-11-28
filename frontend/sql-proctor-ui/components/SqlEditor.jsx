"use client";

import { Component, useEffect, useId, useRef, useState } from "react";

let cachedMonacoPromise = null;

async function defaultLoadMonaco() {
  if (typeof window === "undefined" || typeof window.matchMedia !== "function") {
    return null;
  }

  if (!window.MonacoEnvironment) {
    window.MonacoEnvironment = {
      getWorker() {
        const blob = new Blob(["self.onmessage = function () {};"], {
          type: "application/javascript",
        });
        return new Worker(URL.createObjectURL(blob));
      },
    };
  }

  if (!cachedMonacoPromise) {
    cachedMonacoPromise = import("monaco-editor").catch((err) => {
      cachedMonacoPromise = null;
      throw err;
    });
  }

  return cachedMonacoPromise;
}

function FallbackTextarea({
  id,
  label,
  value,
  onChange,
  readOnly,
  height,
  placeholder,
  ariaDescribedBy,
  onRun,
}) {
  return (
    <textarea
      id={id}
      aria-label={label}
      aria-describedby={ariaDescribedBy}
      value={value ?? ""}
      readOnly={readOnly}
      placeholder={placeholder}
      spellCheck={false}
      onChange={(e) => {
        if (!readOnly) {
          onChange?.(e.target.value);
        }
      }}
      onKeyDown={(e) => {
        if (!readOnly && onRun && (e.ctrlKey || e.metaKey) && e.key === "Enter") {
          e.preventDefault();
          onRun();
        }
      }}
      style={{ minHeight: typeof height === "number" ? `${height}px` : height }}
      className={`block w-full resize-y border-0 bg-white px-3 py-2.5 font-mono text-sm leading-6 text-slate-900 placeholder:text-slate-400 focus:outline-none ${
        readOnly ? "cursor-default bg-slate-50 text-slate-700" : ""
      }`}
    />
  );
}

class SqlEditorErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch(error) {
    this.props.onError?.(error);
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback;
    }
    return this.props.children;
  }
}

export default function SqlEditor({
  id,
  label = "SQL Editor",
  value = "",
  onChange,
  readOnly = false,
  height = 220,
  placeholder = "-- Write MariaDB SQL statements here",
  ariaDescribedBy,
  onRun,
  loadMonaco = defaultLoadMonaco,
}) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const [plainMode, setPlainMode] = useState(false);
  const [ready, setReady] = useState(false);
  const [failed, setFailed] = useState(false);

  const containerRef = useRef(null);
  const editorRef = useRef(null);
  const modelRef = useRef(null);
  const syncingRef = useRef(false);

  const valueRef = useRef(value);
  const onChangeRef = useRef(onChange);
  const onRunRef = useRef(onRun);
  const readOnlyRef = useRef(readOnly);
  const labelRef = useRef(label);
  const loadMonacoRef = useRef(loadMonaco);

  useEffect(() => {
    valueRef.current = value;
  }, [value]);
  useEffect(() => {
    onChangeRef.current = onChange;
  }, [onChange]);
  useEffect(() => {
    onRunRef.current = onRun;
  }, [onRun]);
  useEffect(() => {
    readOnlyRef.current = readOnly;
  }, [readOnly]);
  useEffect(() => {
    labelRef.current = label;
  }, [label]);
  useEffect(() => {
    loadMonacoRef.current = loadMonaco;
  }, [loadMonaco]);

  const showFallback = plainMode || failed;

  useEffect(() => {
    if (showFallback) {
      return undefined;
    }

    let cancelled = false;
    let frameId = null;
    let changeSubscription = null;
    let mountedEditor = null;
    let mountedModel = null;

    async function mountEditor() {
      try {
        const monaco = await loadMonacoRef.current();
        if (cancelled || !monaco?.editor || !containerRef.current || !containerRef.current.isConnected) {
          return;
        }

        const model = monaco.editor.createModel(valueRef.current ?? "", "sql");
        mountedModel = model;

        const editor = monaco.editor.create(containerRef.current, {
          model,
          automaticLayout: true,
          minimap: { enabled: false },
          lineNumbers: "on",
          scrollBeyondLastLine: false,
          wordWrap: "on",
          fontSize: 13,
          tabSize: 2,
          theme: "vs",
          readOnly: readOnlyRef.current,
          domReadOnly: readOnlyRef.current,
          ariaLabel: labelRef.current,
          padding: { top: 10, bottom: 10 },
        });
        mountedEditor = editor;

        if (cancelled) {
          try {
            editor.dispose();
          } catch {
            // Ignore disposal error if canceled mid-mount.
          }
          try {
            model.dispose();
          } catch {
            // Ignore disposal error if canceled mid-mount.
          }
          return;
        }

        editorRef.current = editor;
        modelRef.current = model;

        changeSubscription = editor.onDidChangeModelContent(() => {
          if (cancelled || syncingRef.current || readOnlyRef.current) {
            return;
          }
          onChangeRef.current?.(editor.getValue());
        });

        if (monaco.KeyMod && monaco.KeyCode && typeof editor.addCommand === "function") {
          editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.Enter, () => {
            if (!cancelled && !readOnlyRef.current) {
              onRunRef.current?.();
            }
          });
        }

        setReady(true);
        requestAnimationFrame(() => {
          if (!cancelled && editorRef.current && typeof editorRef.current.layout === "function") {
            editorRef.current.layout();
          }
        });
      } catch {
        if (mountedEditor) {
          try {
            mountedEditor.dispose();
          } catch {
            // Best-effort cleanup on failed init.
          }
        }
        if (mountedModel) {
          try {
            mountedModel.dispose();
          } catch {
            // Best-effort cleanup on failed init.
          }
        }
        if (!cancelled) {
          setReady(false);
          setFailed(true);
        }
      }
    }

    // Defer creation one frame so React StrictMode dev double-mount cancels before constructing Monaco services.
    frameId = requestAnimationFrame(() => {
      if (!cancelled) {
        mountEditor();
      }
    });

    return () => {
      cancelled = true;
      if (frameId !== null) {
        cancelAnimationFrame(frameId);
      }
      editorRef.current = null;
      modelRef.current = null;

      try {
        changeSubscription?.dispose();
      } catch {
        // Ignore listener disposal errors.
      }
      if (mountedEditor) {
        try {
          mountedEditor.dispose();
        } catch {
          // Ignore disposal errors during teardown/Fast Refresh.
        }
      }
      if (mountedModel) {
        try {
          mountedModel.dispose();
        } catch {
          // Ignore disposal errors during teardown/Fast Refresh.
        }
      }
      setReady(false);
    };
  }, [showFallback]);

  useEffect(() => {
    const model = modelRef.current;
    if (!model || showFallback || !ready) {
      return;
    }
    const next = value ?? "";
    if (model.getValue() !== next) {
      syncingRef.current = true;
      try {
        model.setValue(next);
      } finally {
        syncingRef.current = false;
      }
    }
  }, [value, showFallback, ready]);

  useEffect(() => {
    const editor = editorRef.current;
    if (!editor || showFallback || !ready) {
      return;
    }
    editor.updateOptions({
      readOnly,
      domReadOnly: readOnly,
      ariaLabel: label,
    });
  }, [readOnly, label, showFallback, ready]);

  const fallback = (
    <FallbackTextarea
      id={inputId}
      label={label}
      value={value}
      onChange={onChange}
      readOnly={readOnly}
      height={height}
      placeholder={placeholder}
      ariaDescribedBy={ariaDescribedBy}
      onRun={onRun}
    />
  );

  return (
    <div className="overflow-hidden rounded-lg border border-slate-300 bg-white focus-within:border-slate-500 focus-within:ring-1 focus-within:ring-slate-500">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-600">
        <label htmlFor={inputId} className="font-medium text-slate-700">
          {label}
        </label>
        <div className="flex items-center gap-2">
          <span className="rounded bg-slate-200/80 px-1.5 py-0.5 font-mono text-[11px] font-medium text-slate-700">
            {readOnly ? "Read-only" : "MariaDB SQL"}
          </span>
          <button
            type="button"
            onClick={() => {
              setFailed(false);
              setPlainMode((prev) => !prev);
            }}
            className="rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700"
          >
            {plainMode ? "Syntax editor" : "Plain text"}
          </button>
        </div>
      </div>

      {showFallback ? (
        fallback
      ) : (
        <SqlEditorErrorBoundary fallback={fallback} onError={() => setFailed(true)}>
          <div
            className="relative w-full"
            style={{ minHeight: typeof height === "number" ? `${height}px` : height }}
          >
            {!ready ? fallback : null}
            <div
              ref={containerRef}
              className={ready ? "h-full w-full" : "pointer-events-none invisible absolute inset-0 h-full w-full"}
              style={{
                height: typeof height === "number" ? `${height}px` : height,
              }}
            />
          </div>
        </SqlEditorErrorBoundary>
      )}
    </div>
  );
}
