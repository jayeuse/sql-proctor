import { Suspense } from "react";
import ExamEditor from "../../../../components/ExamEditor";

export default function ExamBuilderPage() {
  return (
    <Suspense fallback={<div className="p-6 text-sm text-slate-600">Loading exam builder...</div>}>
      <ExamEditor />
    </Suspense>
  );
}
