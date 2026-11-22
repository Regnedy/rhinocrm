<?php

namespace App\Modules\Partners\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreatePartnerRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', 'max:100'],
            'partner_number' => ['required', 'string', 'regex:/^\d{1,40}$/'],
            'business_name' => ['required', 'string', 'max:200'],
            'country' => ['required', 'string', 'max:80'],
            'country_dial_code' => ['nullable', 'string', 'max:5'],
            'location_name' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'sponsor_partner_id' => ['nullable', 'string', 'max:64'],
            'current_rank_id' => ['nullable', 'string', 'max:64'],
            'admin_name' => ['required', 'string', 'max:160'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'admin_password_confirmation' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }
}
