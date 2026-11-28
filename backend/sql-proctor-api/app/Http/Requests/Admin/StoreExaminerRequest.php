<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreExaminerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        if ($this->has('username') && is_string($this->input('username'))) {
            $updates['username'] = Str::lower(trim($this->input('username')));
        }

        if ($this->has('name') && is_string($this->input('name'))) {
            $trimmed = trim($this->input('name'));
            $updates['name'] = $trimmed === '' ? null : $trimmed;
        }

        if ($this->has('password') && $this->input('password') === '') {
            $updates['password'] = null;
        }

        if ($updates !== []) {
            $this->merge($updates);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:32',
                'regex:/^[a-z0-9][a-z0-9._-]{2,31}$/',
                Rule::unique('users', 'username'),
            ],
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'min:12', 'max:255'],
        ];
    }
}
