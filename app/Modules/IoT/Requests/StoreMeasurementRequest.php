<?php
namespace App\Modules\IoT\Requests;
use Illuminate\Foundation\Http\FormRequest;
final class StoreMeasurementRequest extends FormRequest
{
 public function authorize():bool{return true;}
 protected function prepareForValidation():void
 {
  $system=(array)$this->input('system',[]);$patient=$this->input('paciente',$this->input('patient'));$results=$this->input('resultados');
  if($results===null){$results=['crudos'=>$this->input('raw',[]),'calculados'=>$this->input('calculated',[])];}
  $this->merge(['device_id'=>$system['device_id']??$this->input('device_id'),'id_medicion'=>$system['id_medicion']??$this->input('id_medicion'),'timestamp_local'=>$system['timestamp_local']??$this->input('timestamp_local'),'paciente'=>$patient,'resultados'=>$results]);
 }
 public function rules():array{return [
  'device_id'=>'nullable|string|max:32','id_medicion'=>'required|string|max:100','timestamp_local'=>'required|date',
  'paciente'=>'required|array','paciente.nombre'=>'required|string|max:160','paciente.telefono_whatsapp'=>'required|string|max:32','paciente.tipo_visita'=>'nullable|string|max:40','paciente.edad'=>'required|integer|min:5|max:120','paciente.altura_cm'=>'required|numeric|min:50|max:250','paciente.sexo'=>'required|in:M,F','paciente.perfil'=>'required|in:ATLETA,NORMAL',
  'resultados.crudos'=>'required|array','resultados.crudos.peso_kg'=>'required|numeric|min:1|max:500','resultados.crudos.impedancia_ohm'=>'required|numeric|min:1|max:5000',
  'resultados.calculados'=>'required|array','resultados.calculados.imc'=>'required|numeric','resultados.calculados.grasa_porcentaje'=>'required|numeric','resultados.calculados.masa_magra_kg'=>'required|numeric','resultados.calculados.musculo_porcentaje'=>'required|numeric','resultados.calculados.grasa_subcutanea_porcentaje'=>'required|numeric','resultados.calculados.grasa_visceral'=>'required|numeric','resultados.calculados.agua_porcentaje'=>'required|numeric','resultados.calculados.musculo_esqueletico_porcentaje'=>'required|numeric','resultados.calculados.masa_muscular_kg'=>'required|numeric','resultados.calculados.masa_osea_kg'=>'required|numeric','resultados.calculados.proteina_porcentaje'=>'required|numeric','resultados.calculados.bmr_kcal'=>'required|numeric','resultados.calculados.edad_metabolica'=>'required|numeric',
 ];}
}
