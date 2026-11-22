<?php
namespace App\Modules\CRM\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class CompleteTaskRequest extends FormRequest{public function authorize():bool{return true;}public function rules():array{return ['outcome'=>'required|string|max:500'];}}
