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
                function (string $attribute, mixed $value, \Closure $fail) use ($group): void {
                    if (! $group->organization->members()->where('email', $value)->exists()) {
                        // Said the same whether or not they're on the install, so other organizations' people stay private.
                        $fail(__('No one in this organization has that email address.'));
                    } elseif ($group->members()->where('email', $value)->exists()) {
                        $fail(__('That user is already a member of this group.'));
                    }
                },
            ],
            'role' => ['required', Rule::enum(GroupRole::class)],
        ];
    }
}
