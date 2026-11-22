<?php

namespace App\Modules\Teams\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SetTeamGoalRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'period' => ['required', 'date_format:Y-m'],
            'user_id' => ['nullable', 'string', 'max:128'],
            'prospects_target' => ['nullable', 'integer', 'min:0'],
            'measurements_target' => ['nullable', 'integer', 'min:0'],
            'sales_target' => ['nullable', 'integer', 'min:0'],
            'revenue_target_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
