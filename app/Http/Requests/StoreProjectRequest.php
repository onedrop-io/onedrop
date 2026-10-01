<?php

namespace App\Http\Requests;

use App\Enums\AppTemplate;
use App\Models\Attachment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...Attachment::rules('prompt'),
            // Imported from a repository (PRJ-009), the prompt is optional.
            'prompt' => ['required_without_all:attachments,repository', 'nullable', 'string', 'max:5000'],
            'template' => ['nullable', Rule::enum(AppTemplate::class)],
            'repository' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prompt.required_without_all' => __('Describe what you want to build.'),
        ];
    }
}
