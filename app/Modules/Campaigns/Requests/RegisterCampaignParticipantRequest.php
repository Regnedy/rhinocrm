<?php

namespace App\Modules\Campaigns\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterCampaignParticipantRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'string', 'max:64'],
            'name' => ['required_without:contact_id', 'nullable', 'string', 'max:180'],
            'whatsapp' => ['required_without:contact_id', 'nullable', 'string', 'max:40'],
            'age' => ['nullable', 'integer', 'min:1', 'max:130'],
            'gender' => ['nullable', 'string', 'max:32'],
            'height_cm' => ['nullable', 'numeric', 'min:30', 'max:300'],
            'profile' => ['nullable', 'string', 'max:80'],
            'assigned_advisor_id' => ['nullable', 'string', 'max:128'],
            'follow_up_at' => ['nullable', 'date'],
            'follow_up_title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
