<?php

namespace App\Modules\Workflows\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'trigger_event' => ['required', 'string', 'in:CONTACT_MEASUREMENT_PROCESSED,ADVISOR_ASSIGNED,PROSPECT_STAGE_CHANGED'],
            'trigger_conditions' => ['sometimes', 'array'],
            'nodes' => ['required', 'array', 'min:1'],
            'entry_node_id' => ['required', 'string', 'max:100'],
            'cancel_on_stage_change' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
