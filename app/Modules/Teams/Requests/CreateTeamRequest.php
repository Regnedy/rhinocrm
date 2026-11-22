<?php

namespace App\Modules\Teams\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateTeamRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'location_id' => ['nullable', 'string', 'max:64'],
            'leader_user_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
