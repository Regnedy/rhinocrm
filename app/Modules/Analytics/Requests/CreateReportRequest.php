<?php

namespace App\Modules\Analytics\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'collection' => ['required', 'string', 'in:contacts,measurements,tasks,sales,financial_transactions,inventory_transactions,whatsapp_messages,timeline_activities,users,teams,devices'],
            'columns' => ['required', 'array', 'min:1', 'max:40'],
            'columns.*' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]+$/'],
        ];
    }
}
