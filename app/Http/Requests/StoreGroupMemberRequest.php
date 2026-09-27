<?php

namespace App\Http\Requests;

use App\Enums\GroupRole;
use App\Models\Group;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Group $group */
        $group = $this->route('group');

        return [
            'email' => [
                'required',
                'email',
                Rule::exists('users', 'email'),
                function (string $attribute, mixed $value, \Closure $fail) use ($group): void {
                    if ($group->members()->where('email', $value)->exists()) {
                        $fail(__('That user is already a member of this group.'));
                    }
                },
            ],
            'role' => ['required', Rule::enum(GroupRole::class)],
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
            'email.exists' => __('No user has that email address.'),
        ];
    }
}
