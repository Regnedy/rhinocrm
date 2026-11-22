<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'partner_id' => ['nullable', 'string', 'max:100'],
            'location_id' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'role' => ['required', 'in:PARTNER_ADMIN,LOCATION_MANAGER,TEAM_LEADER,ADVISOR,OPERATOR'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
        ];
    }
}
