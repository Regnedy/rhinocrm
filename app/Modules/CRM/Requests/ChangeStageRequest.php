<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Domain\Services\SalesStageTransitionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(SalesStageTransitionPolicy::stageIds())],
            'loss_reason' => ['nullable', 'required_if:stage,LOST', 'string', 'max:500'],
        ];
    }
}
