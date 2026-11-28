import { Suspense } from "react";
import ExamAttemptsList from "../../../../../components/ExamAttemptsList";

export default function ExamAttemptsPage() {
  return (
    <Suspense fallback={<div className="p-6 text-sm text-slate-600">Loading attempts...</div>}>
      <ExamAttemptsList />
    </Suspense>
  );
}
