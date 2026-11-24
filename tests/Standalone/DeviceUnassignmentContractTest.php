<?php

$root = dirname(__DIR__, 2);
$errors = [];

$read = static function (string $relative) use ($root): string {
    $path = $root.'/'.$relative;
    if (!is_file($path)) {
        throw new RuntimeException('Archivo requerido no encontrado: '.$relative);
    }
    return (string) file_get_contents($path);
};

$checks = [
    'routes/api_v1.php' => [
        'AdminDeviceController::class' => 'La ruta debe usar AdminDeviceController.',
        "Route::post('/devices/{id}/unassign'" => 'Falta la ruta POST de desvinculación.',
    ],
    'resources/views/crm.blade.php' => [
        'rhino-device-unassignment.js' => 'La vista CRM debe cargar el script de desvinculación.',
    ],
    'app/Modules/Administration/Controllers/AdminDeviceController.php' => [
        'DeviceUnassignmentService' => 'El controlador debe delegar en DeviceUnassignmentService.',
        'function unassign(' => 'Falta la acción unassign del controlador.',
    ],
    'app/Modules/Administration/Services/DeviceUnassignmentService.php' => [
        'requireSuperAdmin' => 'La operación debe exigir SUPER_ADMIN en el servicio.',
        "'partner_id' => null" => 'La desvinculación debe liberar el socio.',
        "'location_id' => null" => 'La desvinculación debe liberar la ubicación.',
        "'status' => 'UNASSIGNED'" => 'La báscula debe quedar UNASSIGNED.',
        "'device_token' =>" => 'La desvinculación debe rotar el secreto IoT.',
        "'804'" => 'Debe registrar el movimiento de desvinculación.',
        "'RELEASED'" => 'Debe liberar disponibilidades de eventos.',
        "'INACTIVE'" => 'Debe inactivar asociaciones operativas tradicionales.',
    ],
    'app/Modules/IoT/Services/DeviceManagementService.php' => [
        "'UNASSIGNED'" => 'El registro debe reconocer básculas disponibles.',
        "'805'" => 'La reasignación debe registrar su movimiento.',
        "'reused_unassigned_device'" => 'La respuesta debe identificar la báscula reutilizada.',
        'total_measurements' => 'La reasignación debe conservar el contador histórico.',
    ],
    'app/Modules/IoT/Http/Middleware/VerifyEsp32Signature.php' => [
        'DEVICE_UNASSIGNED' => 'El middleware IoT debe rechazar básculas sin socio.',
    ],
    'app/Modules/IoT/Domain/Entities/Device.php' => [
        "partnerId !== ''" => 'La entidad no debe considerar activa una báscula sin socio.',
        "locationId !== ''" => 'La entidad no debe considerar activa una báscula sin ubicación.',
    ],
    'database/migrations/2026_11_23_000100_add_device_unassignment_support.php' => [
        'nullable()->change()' => 'La migración debe permitir dispositivos sin socio/ubicación.',
        "'code' => '804'" => 'Falta el tipo de movimiento 804.',
        "'code' => '805'" => 'Falta el tipo de movimiento 805.',
    ],
    'public/assets/rhino-device-unassignment.js' => [
        '/unassign' => 'La interfaz debe llamar al endpoint de desvinculación.',
        'Administrador global' => 'La acción visual debe limitarse a SUPER_ADMIN.',
        'unassign-device' => 'Falta el botón de desvinculación.',
    ],
];

foreach ($checks as $file => $needles) {
    $content = $read($file);
    foreach ($needles as $needle => $message) {
        if (!str_contains($content, $needle)) {
            $errors[] = $message.' ('.$file.')';
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors)."\n");
    exit(1);
}

echo "DeviceUnassignmentContractTest OK\n";
