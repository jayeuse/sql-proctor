<?php

namespace App\Http\Requests\Examiner;

use App\Enums\ExpectedBehavior;
use App\Enums\GradingMode;
use App\Enums\QuestionType;
use App\Models\Exam;
use App\Models\ExamQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveExamQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');
        $question = $this->route('question');

        if ($exam instanceof Exam && ! Gate::allows('update', $exam)) {
            return false;
        }

        if ($exam instanceof Exam && $question instanceof ExamQuestion && $question->exam_id !== $exam->id) {
            abort(404);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PATCH') || $this->isMethod('PUT');
        $req = $isUpdate ? 'sometimes' : 'required';

        return [
            'question_type' => [$req, Rule::enum(QuestionType::class)],
            'grading_mode' => [$req, Rule::enum(GradingMode::class)],
            'title' => [$req, 'string', 'max:160'],
            'prompt' => [$req, 'string', 'max:20000'],
            'points' => [$req, 'numeric', 'gt:0', 'max:1000'],
            'starter_sql' => ['sometimes', 'nullable', 'string', 'max:16384'],
            'reference_sql' => ['sometimes', 'nullable', 'string', 'max:32768'],
            'comparison_config' => ['sometimes', 'array'],
            'comparison_config.order_sensitive' => ['sometimes', 'boolean'],
            'comparison_config.column_name_sensitive' => ['sometimes', 'boolean'],
            'comparison_config.case_sensitive_values' => ['sometimes', 'boolean'],
            'comparison_config.float_tolerance' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'comparison_config.required_clauses' => ['sometimes', 'array'],
            'comparison_config.required_clauses.*' => ['string', 'max:100'],
            'comparison_config.forbidden_patterns' => ['sometimes', 'array'],
            'comparison_config.forbidden_patterns.*' => ['string', 'max:100'],
            'rubric' => ['sometimes', 'array'],
            'rubric.*.id' => ['required_with:rubric', 'string', 'max:64'],
            'rubric.*.label' => ['required_with:rubric', 'string', 'max:120'],
            'rubric.*.max_points' => ['required_with:rubric', 'numeric', 'gt:0', 'max:1000'],
            'rubric.*.description' => ['nullable', 'string', 'max:1000'],
            'test_cases' => ['sometimes', 'array'],
            'test_cases.*.name' => ['required_with:test_cases', 'string', 'max:120'],
            'test_cases.*.weight_points' => ['required_with:test_cases', 'numeric', 'gt:0', 'max:1000'],
            'test_cases.*.is_hidden' => ['sometimes', 'boolean'],
            'test_cases.*.override_seed_sql' => ['nullable', 'string', 'max:100000'],
            'test_cases.*.assertion_sql' => ['nullable', 'string', 'max:32768'],
            'test_cases.*.expected_behavior' => ['sometimes', Rule::enum(ExpectedBehavior::class)],
            'options' => ['sometimes', 'array'],
            'options.*.label' => ['required_with:options', 'string', 'max:2000'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var ?ExamQuestion $existing */
            $existing = $this->route('question');
            $type = (string) ($this->input('question_type') ?? $existing?->question_type?->value ?? '');
            $points = (float) ($this->input('points') ?? $existing?->points ?? 0);

            if ($this->has('test_cases')) {
                $testCases = $this->input('test_cases', []);
                if (is_array($testCases) && $testCases !== []) {
                    $sum = 0.0;
                    foreach ($testCases as $tc) {
                        $sum += (float) ($tc['weight_points'] ?? 0);
                    }
                    if (abs($sum - $points) > 0.001) {
                        $validator->errors()->add('test_cases', 'Test case weight_points must sum to the question points.');
                    }
                }
            }

            if ($type === QuestionType::Mcq->value && ($this->isMethod('POST') || $this->has('options'))) {
                $options = $this->input('options', []);
                if (! is_array($options) || count($options) < 2) {
                    $validator->errors()->add('options', 'MCQ questions require at least 2 options.');
                } else {
                    $hasCorrect = false;
                    foreach ($options as $opt) {
                        if ((bool) ($opt['is_correct'] ?? false) === true) {
                            $hasCorrect = true;
                            break;
                        }
                    }
                    if (! $hasCorrect) {
                        $validator->errors()->add('options', 'MCQ questions require at least 1 correct option.');
                    }
                }
            }

            if ($this->has('rubric')) {
                $rubric = $this->input('rubric', []);
                if (is_array($rubric) && $rubric !== []) {
                    $sum = 0.0;
                    foreach ($rubric as $item) {
                        $sum += (float) ($item['max_points'] ?? 0);
                    }
                    if (abs($sum - $points) > 0.001) {
                        $validator->errors()->add('rubric', 'Rubric max_points must sum to the question points.');
                    }
                }
            }
        });
    }
}
