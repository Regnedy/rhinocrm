<?php

namespace App\Modules\IoT\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'mac_address' => ['required', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'location_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
