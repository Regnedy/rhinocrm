<?php
namespace App\Modules\CRM\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class RescheduleTaskRequest extends FormRequest{public function authorize():bool{return true;}public function rules():array{return ['due_at'=>'required|date|after:now'];}}
