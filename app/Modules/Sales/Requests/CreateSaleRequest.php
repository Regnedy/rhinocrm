<?php
namespace App\Modules\Sales\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class CreateSaleRequest extends FormRequest{public function authorize():bool{return true;}public function rules():array{return ['contact_id'=>'required|string|max:100','location_id'=>'required|string|max:100','payment_method'=>'required|in:CASH,CARD,TRANSFER,OTHER','cart'=>'required|array|min:1|max:50','cart.*.product_id'=>'required|string|max:100','cart.*.quantity'=>'required|integer|min:1|max:1000'];}}
