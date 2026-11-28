"use client";

export default function SchemaSummaryPanel({ schemaSummary, emptyText = "No schema summary available yet." }) {
  const tables = Array.isArray(schemaSummary?.tables) ? schemaSummary.tables : [];

  if (tables.length === 0) {
    return (
      <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-xs text-slate-600">
        {emptyText}
      </p>
    );
  }

  return (
    <div className="space-y-3">
      {tables.map((table) => (
        <div key={table.name} className="rounded-lg border border-slate-200 bg-slate-50 p-3">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
            <span className="font-mono text-xs font-semibold text-slate-900">{table.name}</span>
            <span className="rounded bg-white px-2 py-0.5 text-[11px] font-medium text-slate-600 ring-1 ring-slate-200">
              {table.row_count ?? 0} rows
            </span>
          </div>
          <ul className="space-y-1 text-xs">
            {(table.columns ?? []).map((col) => (
              <li key={col.name} className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-mono text-slate-800">
                  {col.name}
                  {col.key === "PRI" && (
                    <span className="ml-1.5 rounded bg-amber-100 px-1 py-0.5 text-[10px] font-semibold text-amber-800">
                      PK
                    </span>
                  )}
                  {col.nullable && (
                    <span className="ml-1 text-[10px] text-slate-500">NULL</span>
                  )}
                </span>
                <span className="font-mono text-[11px] text-slate-600">{col.type}</span>
              </li>
            ))}
          </ul>
          {Array.isArray(table.foreign_keys) && table.foreign_keys.length > 0 && (
            <div className="mt-2 border-t border-slate-200 pt-2 text-[11px] text-slate-600">
              {table.foreign_keys.map((fk, idx) => (
                <div key={`${fk.column}-${idx}`} className="font-mono">
                  FK {fk.column} -&gt; {fk.referenced_table}.{fk.referenced_column}
                </div>
              ))}
            </div>
          )}
        </div>
      ))}
    </div>
  );
}
