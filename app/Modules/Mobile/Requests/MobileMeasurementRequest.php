<?php

namespace App\Modules\Mobile\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MobileMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $patient = (array) $this->input('paciente', $this->input('patient', []));
        $raw = (array) $this->input('resultados.crudos', $this->input('raw', []));
        $calculated = (array) $this->input('resultados.calculados', $this->input('metrics', []));

        $gender = $patient['sexo'] ?? $patient['gender'] ?? null;
        if (is_numeric($gender)) {
            $gender = ((int) $gender) === 1 ? 'M' : 'F';
        }
        $profile = $patient['perfil'] ?? null;
        if ($profile === null && array_key_exists('athlete', $patient)) {
            $profile = filter_var($patient['athlete'], FILTER_VALIDATE_BOOL) ? 'ATLETA' : 'NORMAL';
        }

        $this->merge([
            'device_id' => $this->input('device_id', $this->input('scale.mac')),
            'campaign_id' => $this->input('campaign_id'),
            'id_medicion' => $this->input('id_medicion', $this->input('measurement_id')),
            'timestamp_local' => $this->input('timestamp_local', $this->input('measured_at_utc')),
            'consent_granted' => $this->input('consent_granted', true),
            'paciente' => [
                'nombre' => $patient['nombre'] ?? $patient['name'] ?? null,
                'telefono_whatsapp' => $patient['telefono_whatsapp'] ?? $patient['whatsapp_phone'] ?? $patient['phone'] ?? null,
                'tipo_visita' => $patient['tipo_visita'] ?? 'NUEVO_PROSPECTO',
                'edad' => $patient['edad'] ?? $patient['age'] ?? null,
                'altura_cm' => $patient['altura_cm'] ?? $patient['height_cm'] ?? null,
                'sexo' => $gender,
                'perfil' => $profile,
            ],
            'resultados' => [
                'crudos' => [
                    'peso_kg' => $raw['peso_kg'] ?? $raw['weight_kg'] ?? null,
                    'impedancia_ohm' => $raw['impedancia_ohm'] ?? $raw['impedance_ohm'] ?? null,
                ],
                'calculados' => [
                    'imc' => $calculated['imc'] ?? $calculated['bmi'] ?? null,
                    'grasa_porcentaje' => $calculated['grasa_porcentaje'] ?? $calculated['body_fat_percent'] ?? null,
                    'masa_magra_kg' => $calculated['masa_magra_kg'] ?? $calculated['lean_body_mass_kg'] ?? null,
                    'musculo_porcentaje' => $calculated['musculo_porcentaje'] ?? $calculated['muscle_percent'] ?? null,
                    'grasa_subcutanea_porcentaje' => $calculated['grasa_subcutanea_porcentaje'] ?? $calculated['subcutaneous_fat_percent'] ?? null,
                    'grasa_visceral' => $calculated['grasa_visceral'] ?? $calculated['visceral_fat'] ?? null,
                    'agua_porcentaje' => $calculated['agua_porcentaje'] ?? $calculated['water_percent'] ?? null,
                    'musculo_esqueletico_porcentaje' => $calculated['musculo_esqueletico_porcentaje'] ?? $calculated['skeletal_muscle_percent'] ?? null,
                    'masa_muscular_kg' => $calculated['masa_muscular_kg'] ?? $calculated['muscle_mass_kg'] ?? null,
                    'masa_osea_kg' => $calculated['masa_osea_kg'] ?? $calculated['bone_mass_kg'] ?? null,
                    'proteina_porcentaje' => $calculated['proteina_porcentaje'] ?? $calculated['protein_percent'] ?? null,
                    'bmr_kcal' => $calculated['bmr_kcal'] ?? $calculated['basal_metabolic_rate'] ?? null,
                    'edad_metabolica' => $calculated['edad_metabolica'] ?? $calculated['metabolic_age'] ?? null,
                ],
            ],
        ]);
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:64'],
            'campaign_id' => ['nullable', 'string', 'max:64'],
            'id_medicion' => ['required', 'string', 'max:100'],
            'timestamp_local' => ['required', 'date'],
            'consent_granted' => ['accepted'],
            'paciente' => ['required', 'array'],
            'paciente.nombre' => ['required', 'string', 'max:160'],
            'paciente.telefono_whatsapp' => ['nullable', 'string', 'max:40'],
            'paciente.tipo_visita' => ['nullable', 'string', 'max:40'],
            'paciente.edad' => ['required', 'integer', 'min:5', 'max:120'],
            'paciente.altura_cm' => ['required', 'numeric', 'min:50', 'max:250'],
            'paciente.sexo' => ['required', 'in:M,F'],
            'paciente.perfil' => ['required', 'in:ATLETA,NORMAL'],
            'resultados.crudos' => ['required', 'array'],
            'resultados.crudos.peso_kg' => ['required', 'numeric', 'min:1', 'max:500'],
            'resultados.crudos.impedancia_ohm' => ['required', 'numeric', 'min:1', 'max:5000'],
            'resultados.calculados' => ['required', 'array'],
            'resultados.calculados.imc' => ['required', 'numeric'],
            'resultados.calculados.grasa_porcentaje' => ['required', 'numeric'],
            'resultados.calculados.masa_magra_kg' => ['required', 'numeric'],
            'resultados.calculados.musculo_porcentaje' => ['required', 'numeric'],
            'resultados.calculados.grasa_subcutanea_porcentaje' => ['required', 'numeric'],
            'resultados.calculados.grasa_visceral' => ['required', 'numeric'],
            'resultados.calculados.agua_porcentaje' => ['required', 'numeric'],
            'resultados.calculados.musculo_esqueletico_porcentaje' => ['required', 'numeric'],
            'resultados.calculados.masa_muscular_kg' => ['required', 'numeric'],
            'resultados.calculados.masa_osea_kg' => ['required', 'numeric'],
            'resultados.calculados.proteina_porcentaje' => ['required', 'numeric'],
            'resultados.calculados.bmr_kcal' => ['required', 'numeric'],
            'resultados.calculados.edad_metabolica' => ['required', 'numeric'],
        ];
    }
}
