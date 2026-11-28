import { Suspense } from "react";
import StudentExamWorkspace from "../../../../components/StudentExamWorkspace";

export default function StudentExamWorkspacePage() {
  return (
    <Suspense fallback={<div className="p-6 text-sm text-slate-600">Loading exam workspace...</div>}>
      <StudentExamWorkspace />
    </Suspense>
  );
}
