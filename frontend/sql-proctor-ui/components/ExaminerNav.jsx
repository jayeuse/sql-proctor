"use client";

import Link from "next/link";

const NAV_ITEMS = [
  { key: "classrooms", label: "Classrooms", href: "/examiner" },
  { key: "datasets", label: "Datasets", href: "/examiner/datasets" },
  { key: "exams", label: "Exams", href: "/examiner/exams" },
];

export default function ExaminerNav({ active = "classrooms" }) {
  return (
    <nav aria-label="Examiner sections" className="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
      {NAV_ITEMS.map((item) => {
        const isCurrent = item.key === active;
        return (
          <Link
            key={item.key}
            href={item.href}
            aria-current={isCurrent ? "page" : undefined}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 ${
              isCurrent
                ? "bg-slate-900 text-white"
                : "border border-slate-300 bg-white text-slate-700 hover:bg-slate-100"
            }`}
          >
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}
