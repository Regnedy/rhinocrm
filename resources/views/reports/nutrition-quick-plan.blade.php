@php
$metric = static fn(string $key, string $suffix = '', int $dec = 1) => isset($metrics[$key]) && $metrics[$key] !== null ? number_format((float)$metrics[$key], $dec).$suffix : '—';
$imc = isset($metrics['bmi']) && $metrics['bmi'] !== null ? (float)$metrics['bmi'] : null;
$imcIdx = $imc === null ? null : ($imc < 18.5 ? 0 : ($imc < 25 ? 1 : ($imc < 30 ? 2 : ($imc < 35 ? 3 : ($imc < 40 ? 4 : 5)))));
$imcLabels = ['Bajo peso','Normal','Sobrepeso','Ob. 1','Ob. 2','Ob. 3'];
$imcColors = ['#60a5fa','#4ade80','#facc15','#fb923c','#ef4444','#9333ea'];
$energy = $snapshot['estimated_energy_range'] ?? null;
$macros = (array)($snapshot['macro_targets'] ?? []);
$weekly = (array)($snapshot['weekly_plan'] ?? []);
$shopping = (array)($snapshot['shopping_list'] ?? []);
$portionGuide = (array)($snapshot['portion_guide'] ?? []);
$signals = (array)($quickRecommendations['signals'] ?? []);
$hydration = (array)($quickRecommendations['hydration'] ?? []);
$reset = (array)($quickRecommendations['reset'] ?? []);
$slotLabels = ['breakfast'=>'Desayuno','snack_am'=>'Colación AM','lunch'=>'Comida','snack_pm'=>'Colación PM','dinner'=>'Cena'];
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
@page{margin:20px 24px 24px}*{box-sizing:border-box}body{font-family:"DejaVu Sans",sans-serif;color:#374151;font-size:9px;line-height:1.35;margin:0}.page{page-break-after:always}.page:last-child{page-break-after:auto}.header-layout{width:100%;border-bottom:4px solid #10b981;padding-bottom:10px;margin-bottom:10px;border-collapse:collapse}.header-layout td{vertical-align:bottom}.h-title{font-size:25px;font-weight:800;color:#1f2937;margin:0;letter-spacing:-.4px}.h-subtitle{font-size:11px;font-weight:700;color:#059669;margin:2px 0 0}.h-info{text-align:right;font-size:8.5px;color:#4b5563}.bio-title{font-size:11px;font-weight:800;color:#1f2937;margin:0 0 6px}.biometrics{width:100%;border-collapse:separate;border-spacing:4px;margin-bottom:10px}.biometrics td{border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;padding:5px 4px;text-align:center;width:12.5%}.biometrics strong{display:block;font-size:10px;color:#111827}.biometrics span{display:block;font-size:6.8px;color:#64748b;text-transform:uppercase;margin-top:1px}.sec-title{font-size:12px;font-weight:800;color:#1f2937;border-bottom:2px solid #e5e7eb;padding-bottom:4px;margin:4px 0 7px}.num-badge{background:#1f2937;color:white;width:18px;height:18px;display:inline-block;text-align:center;line-height:18px;border-radius:10px;font-size:9px;margin-right:5px}.num-red{background:#dc2626}.num-indigo{background:#4f46e5}.grid-table{width:100%;border-collapse:collapse;margin-bottom:10px;page-break-inside:avoid}.grid-td{width:48%;vertical-align:top}.gap-td{width:4%}.box-red{background:#fef2f2;border-left:4px solid #dc2626;padding:7px 8px;border-radius:6px;margin-bottom:7px}.box-indigo{background:#eef2ff;border-left:4px solid #4f46e5;padding:7px 8px;border-radius:6px}.box-blue{background:#eff6ff;border-left:4px solid #2563eb;padding:7px 8px;border-radius:6px}.badge{font-size:7px;padding:2px 5px;border-radius:9px;font-weight:800}.badge-blue{background:#dbeafe;color:#1e3a8a}.badge-green{background:#dcfce7;color:#166534}.badge-orange{background:#ffedd5;color:#9a3412}.badge-red{background:#fee2e2;color:#991b1b}.badge-purple{background:#f3e8ff;color:#6b21a8}.thermo{width:100%;border-collapse:collapse;margin-top:6px}.thermo td{height:8px;padding:0}.thermo-labels{width:100%;font-size:6px;color:#64748b;text-align:center;border-collapse:collapse}.thermo-labels td{width:16.66%;padding:2px 0}.pointer-row{width:100%;border-collapse:collapse}.pointer-row td{width:16.66%;height:18px;text-align:center;vertical-align:bottom}.pointer{display:inline-block;background:#1f2937;color:white;padding:1px 4px;border-radius:3px;font-size:7px;font-weight:800}.pointer-triangle{margin:0 auto;width:0;height:0;border-left:3px solid transparent;border-right:3px solid transparent;border-top:4px solid #1f2937}.macros-container{width:100%;border-collapse:separate;border-spacing:5px;margin-bottom:9px}.macros-container td{width:25%;border:1px solid #e5e7eb;border-radius:7px;background:#fff;text-align:center;padding:7px 4px}.m-num{display:block;font-size:15px;font-weight:800}.m-title{display:block;font-size:7px;font-weight:800;color:#374151;text-transform:uppercase}.m-desc{display:block;font-size:6.5px;color:#6b7280;margin-top:2px}.portions-box{border:1px solid #d1fae5;background:#f0fdf4;border-radius:7px;padding:6px;margin-bottom:10px}.portions-table{width:100%;border-collapse:collapse}.portions-table td{width:20%;padding:3px;font-size:7.2px;vertical-align:top}.rules-table{width:100%;border-collapse:collapse}.rules-table td{padding:2px;vertical-align:top;font-size:7.5px}.check{color:#059669;font-weight:bold;width:12px}.cross{color:#dc2626;font-weight:bold;width:12px}.detox-wrapper{background:#f0fdf4;border:1px solid #6ee7b7;border-left:5px solid #10b981;border-radius:8px;padding:9px;margin:6px 0 10px;page-break-inside:avoid}.detox-title{color:#047857;font-size:10.5px;font-weight:800;margin:0 0 4px}.detox-text{color:#065f46;font-size:7.7px;margin:0;line-height:1.45}.shopping{width:100%;border-collapse:separate;border-spacing:4px}.shopping td{width:33.33%;vertical-align:top;border:1px solid #e5e7eb;border-radius:6px;padding:6px}.shopping strong{color:#047857;font-size:8px}.shopping ul{margin:3px 0 0;padding-left:13px}.shopping li{margin:1px 0;font-size:7px}.weekly{width:100%;border-collapse:collapse;table-layout:fixed;font-size:6.8px}.weekly th{background:#0f8b6d;color:#fff;padding:5px 2px;border:1px solid #d8e3df;font-size:6.6px}.weekly td{padding:5px 3px;border-bottom:1px solid #e5e7eb;vertical-align:top}.weekly tr:nth-child(even) td{background:#f9fafb}.weekly .day{font-weight:800;width:8%}.meal-name{font-weight:700;color:#111827;line-height:1.25}.recipe{border-bottom:1px solid #e5e7eb;padding:5px 0;page-break-inside:avoid}.recipe:last-child{border:0}.recipe h3{font-size:8.3px;color:#047857;margin:0 0 2px}.recipe p{margin:1px 0;font-size:7px}.notice{border:1px solid #f2d066;border-left:4px solid #eab308;background:#fffbeb;border-radius:6px;padding:7px 8px;margin:6px 0}.footer-note{border-top:1px solid #e5e7eb;margin-top:10px;padding-top:6px;color:#6b7280;font-size:6.5px;text-align:center}.muted{color:#6b7280}.small{font-size:7px}.no-break{page-break-inside:avoid}
</style>
</head>
<body>
<div class="page">
<table class="header-layout"><tr><td><div class="h-title">PLAN ALIMENTICIO RÁPIDO</div><div class="h-subtitle">RHINO · Basado directamente en tu pesaje</div></td><td class="h-info"><strong>{{ $plan->contact_name }}</strong><br>{{ $measurement?->timestamp_local ?? $plan->generated_at }}<br>{{ $plan->partner_name ?? 'RHINO CRM' }}</td></tr></table>

<div class="bio-title">Datos biométricos del pesaje</div>
<table class="biometrics"><tr>
<td><strong>{{ $metric('weight_kg',' kg') }}</strong><span>Peso</span></td><td><strong>{{ $metric('bmi') }}</strong><span>IMC</span></td><td><strong>{{ $metric('body_fat_percent',' %') }}</strong><span>Grasa</span></td><td><strong>{{ $metric('muscle_mass_kg',' kg') }}</strong><span>Masa muscular</span></td><td><strong>{{ $metric('muscle_percent',' %') }}</strong><span>Músculo</span></td><td><strong>{{ $metric('body_water_percent',' %') }}</strong><span>Agua</span></td><td><strong>{{ $metric('visceral_fat','',0) }}</strong><span>Grasa visceral</span></td><td><strong>{{ $metric('bmr_kcal',' kcal',0) }}</strong><span>Metabolismo basal</span></td>
</tr><tr>
<td><strong>{{ $metric('subcutaneous_fat_percent',' %') }}</strong><span>Grasa subcutánea</span></td><td><strong>{{ $metric('skeletal_muscle_percent',' %') }}</strong><span>Músculo esquelético</span></td><td><strong>{{ $metric('lean_mass_kg',' kg') }}</strong><span>Masa magra</span></td><td><strong>{{ $metric('bone_mass_kg',' kg') }}</strong><span>Masa ósea</span></td><td><strong>{{ $metric('protein_percent',' %') }}</strong><span>Proteína</span></td><td><strong>{{ $metric('metabolic_age',' años',0) }}</strong><span>Edad metabólica</span></td><td><strong>{{ $metric('height_cm',' cm') }}</strong><span>Estatura</span></td><td><strong>{{ $metrics['age'] ?? '—' }}</strong><span>Edad</span></td>
</tr></table>

<table class="grid-table"><tr><td class="grid-td">
<div class="sec-title"><span class="num-badge">1</span> Tu Resultado Actual</div>
<div><strong>IMC:</strong> {{ $imc !== null ? number_format($imc,1) : '—' }} @if($imcIdx!==null)<span class="badge {{ $imcIdx===0?'badge-blue':($imcIdx===1?'badge-green':($imcIdx===2?'badge-orange':($imcIdx<5?'badge-red':'badge-purple'))) }}">{{ $imcLabels[$imcIdx] }}</span>@endif</div>
<table class="pointer-row" id="termometro-imc"><tr>@for($i=0;$i<6;$i++)<td>@if($imcIdx===$i)<span class="pointer">{{ number_format($imc,1) }}</span><div class="pointer-triangle"></div>@endif</td>@endfor</tr></table>
<table class="thermo"><tr>@foreach($imcColors as $color)<td style="background:{{ $color }}"></td>@endforeach</tr></table>
<table class="thermo-labels"><tr>@foreach($imcLabels as $label)<td>{{ $label }}</td>@endforeach</tr></table>
</td><td class="gap-td"></td><td class="grid-td">
<div class="box-red"><strong style="color:#b91c1c">► Señales a cuidar</strong>@if($signals)<ul style="margin:3px 0 0;padding-left:14px">@foreach($signals as $signal)<li>{{ $signal }}</li>@endforeach</ul>@else<p style="margin:3px 0 0">No se activaron señales biométricas adicionales en este pesaje.</p>@endif</div>
<div class="box-indigo"><strong style="color:#3730a3">✓ Regla del plan rápido</strong><p style="margin:3px 0 0">Este plan usa automáticamente los valores del Kiosco. No requiere volver a capturar peso, talla, edad, sexo ni composición corporal.</p></div>
</td></tr></table>

<div class="sec-title"><span class="num-badge">2</span> Metas Clave Diarias</div>
<table class="macros-container"><tr>
<td><span class="m-num" style="color:#059669">{{ $macros['kcal'] ?? '—' }}</span><span class="m-title">Kcal / día</span><span class="m-desc">{{ $energy['label'] ?? 'Orientación general' }}</span></td>
<td><span class="m-num" style="color:#2563eb">{{ isset($macros['protein_g'])?$macros['protein_g'].'g':'—' }}</span><span class="m-title">Proteína</span><span class="m-desc">Apoyo a masa magra</span></td>
<td><span class="m-num" style="color:#d97706">{{ isset($macros['carbs_g'])?$macros['carbs_g'].'g':'—' }}</span><span class="m-title">Carbohidratos</span><span class="m-desc">Prioriza fibra</span></td>
<td><span class="m-num" style="color:#9333ea">{{ isset($macros['fat_g'])?$macros['fat_g'].'g':'—' }}</span><span class="m-title">Grasas</span><span class="m-desc">Fuentes de calidad</span></td>
</tr></table>

<div class="sec-title"><span class="num-badge">3</span> Guía Rápida de Porciones</div>
<div class="portions-box"><table class="portions-table"><tr>@foreach(array_slice($portionGuide,0,5) as $portion)<td><strong>{{ $portion['group'] ?? $portion['label'] ?? '' }}</strong><br>{{ $portion['guidance'] ?? $portion['amount'] ?? '' }}</td>@endforeach</tr></table></div>

<table class="grid-table"><tr><td class="grid-td"><div class="sec-title"><span class="num-badge">4</span> Hidratación</div><div class="box-blue"><strong>Meta orientativa: {{ $hydration['target_liters'] ?? '—' }} L/día</strong><p style="margin:4px 0 0">{{ $hydration['text'] ?? ($snapshot['hydration'] ?? '') }}</p></div></td><td class="gap-td"></td><td class="grid-td"><div class="sec-title"><span class="num-badge num-red">5</span> Hábitos a limitar</div><table class="rules-table"><tr><td class="cross">×</td><td>Bebidas azucaradas y exceso de jugos.</td></tr><tr><td class="cross">×</td><td>Ultraprocesados y frituras frecuentes.</td></tr><tr><td class="cross">×</td><td>Exceso de alcohol y comidas muy saladas.</td></tr><tr><td class="cross">×</td><td>Saltarse comidas para “compensar”.</td></tr></table></td></tr></table>

<div class="detox-wrapper"><div class="detox-title">Desintoxicación / reinicio</div><p class="detox-text">{{ $reset['text'] ?? '' }}</p></div>
<div class="footer-note">Los cálculos son orientativos y se basan en el pesaje registrado. {{ $disclaimer }}</div>
</div>

<div class="page">
<table class="header-layout"><tr><td><div class="h-title" style="font-size:20px">TU SEMANA DE ALIMENTACIÓN</div><div class="h-subtitle">5 tiempos · opciones prácticas · biblioteca RHINO</div></td><td class="h-info">{{ $plan->contact_name }}<br>Plan {{ $plan->id }}</td></tr></table>
<div class="sec-title"><span class="num-badge">6</span> Menú Semanal</div>
<table class="weekly"><thead><tr><th>Día</th><th>Desayuno</th><th>Colación AM</th><th>Comida</th><th>Colación PM</th><th>Cena</th></tr></thead><tbody>
@foreach($weekly as $day)<tr><td class="day">{{ $day['day'] ?? '' }}</td>@foreach(['breakfast','snack_am','lunch','snack_pm','dinner'] as $slot)<td><div class="meal-name">{{ $day[$slot] ?? '—' }}</div></td>@endforeach</tr>@endforeach
</tbody></table>

@if($shopping)<div class="sec-title" style="margin-top:10px"><span class="num-badge">7</span> Lista de Súper Inteligente</div><table class="shopping"><tr>@foreach($shopping as $group=>$values)<td><strong>{{ $group }}</strong><ul>@foreach(array_slice((array)$values,0,14) as $value)<li>{{ $value }}</li>@endforeach</ul></td>@if($loop->iteration%3===0)</tr><tr>@endif @endforeach</tr></table>@endif

<div class="sec-title" style="margin-top:10px"><span class="num-badge num-indigo">8</span> Reglas de Oro</div>
<table class="grid-table"><tr><td class="grid-td"><table class="rules-table"><tr><td class="check">✓</td><td>Usa agua simple como bebida principal.</td></tr><tr><td class="check">✓</td><td>Incluye una fuente de proteína en cada comida principal.</td></tr><tr><td class="check">✓</td><td>Prioriza verduras y frutas enteras sobre jugos.</td></tr></table></td><td class="gap-td"></td><td class="grid-td"><table class="rules-table"><tr><td class="check">✓</td><td>Respeta tus porciones y evita comer directamente del paquete.</td></tr><tr><td class="check">✓</td><td>Organiza al menos una comida con anticipación.</td></tr><tr><td class="check">✓</td><td>Repite el pesaje para comparar tendencia, no un solo número.</td></tr></table></td></tr></table>
<div class="footer-note">Plan generado automáticamente desde RHINO CRM · Fuente: pesaje {{ $plan->measurement_id }}</div>
</div>

<div class="page">
<table class="header-layout"><tr><td><div class="h-title" style="font-size:20px">RECETAS Y PREPARACIÓN</div><div class="h-subtitle">Opciones incluidas en tu plan rápido</div></td><td class="h-info">{{ $plan->contact_name }}</td></tr></table>
<div class="sec-title"><span class="num-badge">9</span> Preparaciones</div>
@foreach($recipes as $recipe)<div class="recipe"><h3>{{ trim(($recipe['emoji'] ?? '').' '.($recipe['name'] ?? 'Preparación')) }}</h3><p><strong>Porción:</strong> {{ $recipe['portion_text'] ?? 'Porción base del plan' }}</p>@if(!empty($recipe['ingredients']))<p><strong>Ingredientes:</strong> @foreach((array)$recipe['ingredients'] as $ing){{ is_array($ing)?($ing['name']??$ing['display']??''):$ing }}@if(!$loop->last), @endif @endforeach</p>@endif @if(!empty($recipe['preparation']))<p><strong>Preparación:</strong> {{ $recipe['preparation'] }}</p>@endif</div>@endforeach
<div class="notice"><strong>Ante antojos:</strong> primero toma agua, espera unos minutos y elige una colación del plan. Si el hambre persiste, prioriza fruta entera, yogur natural o una porción medida de semillas según tolerancia.</div>
<div class="detox-wrapper"><div class="detox-title">Seguimiento</div><p class="detox-text">Este documento conserva un snapshot de los datos del pesaje con el que fue generado. Un pesaje posterior no modifica este plan; RHINO puede generar una nueva versión para comparar avances.</p></div>
<div class="footer-note">{{ $disclaimer }}</div>
</div>
</body>
</html>
