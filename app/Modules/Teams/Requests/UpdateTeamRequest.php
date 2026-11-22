<?php

namespace App\Modules\Teams\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'location_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'leader_user_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'status' => ['sometimes', 'required', 'in:ACTIVE,SUSPENDED'],
        ];
    }
}
