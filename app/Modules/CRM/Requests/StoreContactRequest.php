<?php

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreContactRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'age' => ['nullable', 'integer', 'min:1', 'max:130'],
            'gender' => ['nullable', 'string', 'max:32'],
            'height_cm' => ['nullable', 'numeric', 'min:30', 'max:300'],
            'profile' => ['nullable', 'string', 'max:80'],
            'assigned_advisor_id' => ['nullable', 'string', 'max:128'],
            'team_id' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:80'],
        ];
    }
}
