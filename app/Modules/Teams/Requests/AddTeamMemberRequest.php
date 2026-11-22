<?php

namespace App\Modules\Teams\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddTeamMemberRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'max:128'],
            'member_role' => ['nullable', 'in:MEMBER,LEADER'],
        ];
    }
}
