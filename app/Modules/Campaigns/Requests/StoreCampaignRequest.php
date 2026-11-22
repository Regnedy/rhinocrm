<?php

namespace App\Modules\Campaigns\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:WEIGHING_EVENT,HOME_PARTY,PLAZA,STREET,BRANCH,TEST,SUPERMARKET,FAIR,COMMUNITY,CORPORATE,DEMO,OTHER'],
            'venue_name' => ['nullable', 'string', 'max:180'],
            'address' => ['nullable', 'string', 'max:300'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'team_id' => ['nullable', 'string', 'max:64'],
            'responsible_user_id' => ['nullable', 'string', 'max:128'],
            'prospects_target' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'measurements_target' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'budget_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'member_user_ids' => ['nullable', 'array', 'max:100'],
            'member_user_ids.*' => ['string', 'max:128', 'distinct'],
            'device_ids' => ['nullable', 'array', 'max:50'],
            'device_ids.*' => ['string', 'max:64', 'distinct'],
        ];
    }
}
