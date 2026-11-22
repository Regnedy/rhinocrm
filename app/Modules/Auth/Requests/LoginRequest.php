<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:180'],
            'device_fingerprint' => ['nullable', 'string', 'max:500'],
            'android_id' => ['nullable', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'fcm_token' => ['nullable', 'string', 'max:4096'],
        ];
    }
}
