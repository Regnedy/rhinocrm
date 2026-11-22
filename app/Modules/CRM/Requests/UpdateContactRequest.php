<?php

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'whatsapp' => ['sometimes', 'required', 'string', 'max:40'],
            'age' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:130'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:32'],
            'height_cm' => ['sometimes', 'nullable', 'numeric', 'min:30', 'max:300'],
            'profile' => ['sometimes', 'nullable', 'string', 'max:80'],
            'assigned_advisor_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'team_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
