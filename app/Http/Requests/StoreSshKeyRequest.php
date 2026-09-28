<?php

namespace App\Http\Requests;

use App\Models\SshKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSshKeyRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:100'],
            'public_key' => ['required', 'string', 'max:16000', function (string $attribute, string $value, Closure $fail) {
                if (str_contains($value, 'PRIVATE KEY')) {
                    $fail(__("That's a private key. Paste the public key instead (the .pub file, e.g. ~/.ssh/id_ed25519.pub), and keep the private key to yourself."));

                    return;
                }

                if (SshKey::parse($value) === null) {
                    $fail(__("That doesn't look like an SSH public key. It should start with ssh-ed25519, ssh-rsa or ecdsa-sha2-…, like the contents of ~/.ssh/id_ed25519.pub."));

                    return;
                }

                if ($this->user()->sshKeys()->where('fingerprint', SshKey::parse($value)['fingerprint'])->exists()) {
                    $fail(__('This key is already on your account.'));
                }
            }],
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
            'public_key.required' => __('Paste your SSH public key.'),
        ];
    }
}
