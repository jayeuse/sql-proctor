import { Suspense } from "react";
import AttemptGradingView from "../../../../components/AttemptGradingView";

export default function AttemptGradingPage() {
  return (
    <Suspense fallback={<div className="p-6 text-sm text-slate-600">Loading grading view...</div>}>
      <AttemptGradingView />
    </Suspense>
  );
}
