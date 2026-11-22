<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 26px 28px 34px; }
    body { font-family: DejaVu Sans, sans-serif; color: #12213d; font-size: 9.5px; line-height: 1.42; margin: 0; }
    .header { background: #5dc9ca; border-radius: 18px; padding: 20px 23px; color: #0a2131; }
    .brand { font-size: 23px; font-weight: 700; letter-spacing: 1px; }
    .subtitle { font-size: 11px; margin-top: 3px; }
    .person { margin-top: 16px; font-size: 18px; font-weight: 700; }
    .meta { color: #36536a; margin-top: 5px; }
    .code { display: inline-block; margin-top: 9px; padding: 6px 10px; border-radius: 8px; background: rgba(255,255,255,.65); font-weight: 700; letter-spacing: .7px; }
    .summary { width: 100%; border-collapse: separate; border-spacing: 9px 0; margin: 16px -9px 0; }
    .summary td { width: 33.33%; background: #f5f8fb; border: 1px solid #dbe5ee; border-radius: 12px; padding: 12px; text-align: center; }
    .summary strong { display: block; font-size: 16px; color: #10223e; }
    .summary span { color: #66788d; font-size: 8.5px; }
    .section { margin-top: 17px; }
    h2 { font-size: 14px; margin: 0 0 8px; color: #10223e; border-bottom: 2px solid #5dc9ca; padding-bottom: 5px; }
    h3 { font-size: 11px; margin: 0 0 6px; color: #19324d; }
    .notice { background: #eef9f8; border-left: 4px solid #35aeb1; padding: 9px 11px; border-radius: 7px; }
    .warning { background: #fff7e8; border-left-color: #e6a11c; }
    .danger { background: #fff0f0; border-left-color: #d65353; }
    table.metrics { width: 100%; border-collapse: collapse; }
    .metrics th { color: #758497; font-weight: 400; text-align: left; border-bottom: 1px solid #dbe5ee; padding: 7px 8px; }
    .metrics td { padding: 7px 8px; border-bottom: 1px solid #edf1f5; }
    .metrics tr:nth-child(even) td { background: #f8fafc; }
    .metrics .value { font-weight: 700; }
    .change-pos { color: #167b45; }
    .change-neg { color: #bd4b39; }
    .grid { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 -8px; }
    .grid td { width: 50%; vertical-align: top; padding: 9px; border: 1px solid #dbe5ee; border-radius: 9px; background: #fafcfe; }
    .grid strong { display: block; margin-bottom: 4px; color: #19324d; }
    .facts { width: 100%; border-collapse: collapse; }
    .facts td { width: 50%; padding: 6px 8px; border-bottom: 1px solid #edf1f5; vertical-align: top; }
    .portion-table { width: 100%; border-collapse: collapse; }
    .portion-table td { padding: 7px 8px; border-bottom: 1px solid #edf1f5; vertical-align: top; }
    .portion-table td:first-child { width: 28%; font-weight: 700; color: #19324d; }
    .day-card { border: 1px solid #dbe5ee; border-radius: 10px; padding: 9px 10px; margin: 8px 0; page-break-inside: avoid; }
    .day-title { font-size: 11px; font-weight: 700; color: #0f766e; margin-bottom: 5px; }
    .meal-table { width: 100%; border-collapse: collapse; }
    .meal-table td { padding: 4px 5px; border-top: 1px solid #edf1f5; vertical-align: top; }
    .meal-table tr:first-child td { border-top: 0; }
    .meal-label { width: 19%; font-weight: 700; color: #40566d; }
    .shopping { width: 100%; border-collapse: separate; border-spacing: 7px; margin: 0 -7px; }
    .shopping td { width: 33.33%; border: 1px solid #dbe5ee; background: #fafcfe; border-radius: 8px; padding: 8px; vertical-align: top; }
    .shopping strong { color: #19324d; }
    ul { padding-left: 17px; margin: 7px 0; }
    li { margin: 3px 0; }
    .energy { font-size: 15px; font-weight: 700; color: #0f766e; }
    .pill { display: inline-block; border-radius: 20px; background: #e8f7f6; color: #116466; padding: 4px 8px; margin: 2px 3px 2px 0; font-size: 8px; }
    .page-break { page-break-before: always; }
    .footer { margin-top: 18px; padding-top: 9px; border-top: 1px solid #dbe5ee; color: #6d7c8d; font-size: 7.5px; }
    .small { font-size: 7.8px; color: #697a8e; }
</style>
</head>
<body>
<div class="header">
    <div class="brand">RHINO</div>
    <div class="subtitle">Informe de composición corporal y alimentación recomendada</div>
    <div class="person">{{ $report->contact_name }}</div>
    <div class="meta">
        Pesaje: {{ \Carbon\Carbon::parse($report->timestamp_local)->timezone(config('app.timezone'))->translatedFormat('d F Y, H:i') }}<br>
        Evento: {{ $report->campaign_name }}
        @if($report->venue_name || $report->city)
            · {{ trim(($report->venue_name ?? '').' '.($report->city ?? '')) }}
        @endif
    </div>
    <div class="code">Código RHINO: {{ $report->public_code }}</div>
</div>

@php
$metricMap = collect($metrics)->keyBy('label');
$weight = $metricMap->get('Peso');
$bmi = $metricMap->get('IMC estimado');
$fat = $metricMap->get('Grasa corporal estimada');
@endphp

<table class="summary">
<tr>
    <td><strong>{{ $weight['value'] ?? '—' }}</strong><span>Peso actual</span></td>
    <td><strong>{{ $bmi['value'] ?? '—' }}</strong><span>IMC estimado</span></td>
    <td><strong>{{ $fat['value'] ?? '—' }}</strong><span>Grasa corporal estimada</span></td>
</tr>
</table>

<div class="section">
    <div class="notice">
        Este documento combina tu historial de pesajes con una alimentación recomendada de orientación general. Las mediciones por bioimpedancia son estimaciones y el menú no constituye diagnóstico ni prescripción clínica.
    </div>
</div>

<div class="section">
    <h2>Tu historial RHINO</h2>
    <p>
        Primer pesaje registrado:
        <strong>{{ $firstMeasuredAt ? \Carbon\Carbon::parse($firstMeasuredAt)->translatedFormat('d/m/Y') : 'este pesaje' }}</strong>
        · Pesajes acumulados: <strong>{{ $measurementCount }}</strong>.
    </p>
    <table class="metrics">
        <thead><tr><th>Indicador</th><th>Valor actual</th><th>Primer registro</th><th>Cambio</th></tr></thead>
        <tbody>
        @foreach($metrics as $metric)
            @php $change = $metric['change'] !== null ? (float)$metric['change'] : null; @endphp
            <tr>
                <td>{{ $metric['label'] }}</td>
                <td class="value">{{ $metric['value'] }}</td>
                <td>{{ $metric['first_value'] ?? '—' }}</td>
                <td class="{{ $change !== null && $change > 0 ? 'change-pos' : ($change !== null && $change < 0 ? 'change-neg' : '') }}">
                    @if($change === null)
                        —
                    @else
                        {{ $change > 0 ? '+' : '' }}{{ $metric['change'] }} {{ $metric['unit'] }}
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="small">Los cambios no se califican automáticamente como buenos o malos porque su interpretación depende del objetivo, historial y condiciones de cada pesaje.</p>
</div>

<div class="page-break"></div>

<div class="section">
    <h2>{{ $nutritionPlan['title'] }}</h2>

    @if($nutritionPlan['requires_professional_review'])
        <div class="notice danger">
            Este plan se entrega como orientación preliminar. Por la información declarada, consulta a un médico o profesional de nutrición antes de aplicarlo o modificar tu alimentación.
        </div>
    @elseif(!$nutritionPlan['profile_complete'])
        <div class="notice warning">
            El cuestionario nutricional está incompleto. El menú se basa en tus datos disponibles y utiliza opciones generales; puede personalizarse mejor cuando registres objetivos, actividad, alergias, preferencias y horarios.
        </div>
    @endif

    <h3 style="margin-top:10px;">Datos considerados</h3>
    @forelse($nutritionPlan['based_on'] as $item)
        <span class="pill">{{ $item }}</span>
    @empty
        <p>Se utilizó la información disponible en el pesaje actual.</p>
    @endforelse

    @if($nutritionPlan['estimated_energy_range'])
        <div class="notice" style="margin-top:10px;">
            <div class="energy">{{ $nutritionPlan['estimated_energy_range']['label'] }}</div>
            <div class="small">Rango estimado para orientación; no es una meta clínica ni debe usarse para dietas extremas.</div>
        </div>
    @endif

    <ul>
        @foreach($nutritionPlan['notes'] as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ul>
</div>

<div class="section">
    <h2>Guía visual de porciones</h2>
    <table class="portion-table">
        @foreach($nutritionPlan['portion_guide'] as $portion)
            <tr>
                <td>{{ $portion['group'] }}</td>
                <td>{{ $portion['guidance'] }}</td>
            </tr>
        @endforeach
    </table>
    <div class="notice" style="margin-top:8px;">{{ $nutritionPlan['hydration'] }}</div>
</div>

<div class="section">
    <h2>Menú recomendado de 7 días</h2>
    <p class="small">Puedes intercambiar días y comidas equivalentes. Evita cualquier alimento que te haya causado alergia o reacción, aunque aparezca por error en esta guía.</p>

    @foreach($nutritionPlan['weekly_plan'] as $day)
        <div class="day-card">
            <div class="day-title">{{ $day['day'] }}</div>
            <table class="meal-table">
                <tr><td class="meal-label">Desayuno</td><td>{{ $day['breakfast'] }}</td></tr>
                @if($day['snack_am'])
                    <tr><td class="meal-label">Colación 1</td><td>{{ $day['snack_am'] }}</td></tr>
                @endif
                <tr><td class="meal-label">Comida</td><td>{{ $day['lunch'] }}</td></tr>
                @if($day['snack_pm'])
                    <tr><td class="meal-label">Colación 2</td><td>{{ $day['snack_pm'] }}</td></tr>
                @endif
                <tr><td class="meal-label">Cena</td><td>{{ $day['dinner'] }}</td></tr>
            </table>
        </div>
    @endforeach
</div>

<div class="page-break"></div>

<div class="section">
    <h2>Sustituciones prácticas</h2>
    <table class="metrics">
        <thead><tr><th>En lugar de</th><th>Puedes elegir</th></tr></thead>
        <tbody>
        @foreach($nutritionPlan['substitutions'] as $substitution)
            <tr>
                <td class="value">{{ $substitution['from'] }}</td>
                <td>{{ $substitution['to'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="section">
    <h2>Lista de compras sugerida</h2>
    @foreach(array_chunk($nutritionPlan['shopping_list'], 3, true) as $chunk)
        <table class="shopping">
            <tr>
            @foreach($chunk as $category => $items)
                <td>
                    <strong>{{ $category }}</strong>
                    <ul>
                        @foreach($items as $item)<li>{{ $item }}</li>@endforeach
                    </ul>
                </td>
            @endforeach
            @for($i = count($chunk); $i < 3; $i++)<td></td>@endfor
            </tr>
        </table>
    @endforeach
</div>

<div class="section">
    <h2>Próximo paso</h2>
    <div class="notice warning">{{ $nutritionPlan['medical_note'] }}</div>
    <p>
        Conserva tu código <strong>{{ $report->public_code }}</strong> y realiza el próximo pesaje en condiciones semejantes.
        El historial permitirá comparar tendencias y ajustar futuras recomendaciones.
    </p>
    @if($report->responsible_name)
        <p>Responsable RHINO: <strong>{{ $report->responsible_name }}</strong></p>
    @endif
</div>

<div class="footer">
    Generado automáticamente por Rhino CRM el {{ $generatedAt->timezone(config('app.timezone'))->translatedFormat('d/m/Y H:i') }}.<br>
    Algoritmo de alimentación recomendada: {{ $nutritionPlan['algorithm_version'] }}.<br>
    {{ $disclaimer }}
</div>
</body>
</html>
