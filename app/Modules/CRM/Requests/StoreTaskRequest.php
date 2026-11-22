<?php

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'string', 'max:64'],
            'assigned_user_id' => ['nullable', 'string', 'max:128'],
            'campaign_id' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['required', 'date', 'after:now'],
        ];
    }
}
