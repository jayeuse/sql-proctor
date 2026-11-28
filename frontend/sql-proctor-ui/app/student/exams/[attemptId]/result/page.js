import { Suspense } from "react";
import StudentExamResult from "../../../../../components/StudentExamResult";

export default function StudentExamResultPage() {
  return (
    <Suspense fallback={<div className="p-6 text-sm text-slate-600">Loading exam result...</div>}>
      <StudentExamResult />
    </Suspense>
  );
}
