<?php

namespace App\Http\Requests;

use App\Models\Attachment;
use App\Sandbox\Templates\TemplateCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            // A built-in template (PRJ-004) or one from a registry (PRJ-012), which needs Docker in new sandboxes.
            'template' => ['nullable', 'string', 'max:200', function (string $attribute, string $value, Closure $fail): void {
                $catalog = app(TemplateCatalog::class);
                $template = $catalog->find($value);

                if (! $template) {
                    $fail(__('That template isn\'t available.'));
                } elseif ($template['compose'] && ! $catalog->canRunCompose()) {
                    $fail(__(':name runs with Docker Compose, and new projects\' sandboxes can\'t run Docker. An admin can turn on Docker inside sandboxes in Settings → Sandboxes.', ['name' => $template['label']]));
                }
            }],
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
