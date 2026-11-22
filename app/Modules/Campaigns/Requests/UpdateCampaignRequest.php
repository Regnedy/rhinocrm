<?php

namespace App\Modules\Campaigns\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'type' => ['sometimes', 'required', 'in:WEIGHING_EVENT,HOME_PARTY,PLAZA,STREET,BRANCH,TEST,SUPERMARKET,FAIR,COMMUNITY,CORPORATE,DEMO,OTHER'],
            'venue_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'team_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'responsible_user_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'prospects_target' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'measurements_target' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'budget_minor' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'member_user_ids' => ['sometimes', 'array', 'max:100'],
            'member_user_ids.*' => ['string', 'max:128', 'distinct'],
            'device_ids' => ['sometimes', 'array', 'max:50'],
            'device_ids.*' => ['string', 'max:64', 'distinct'],
        ];
    }
}
