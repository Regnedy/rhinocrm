<?php
namespace App\Modules\IoT\Domain\Exceptions;
use RuntimeException;
final class MeasurementContactConflictException extends RuntimeException { public function __construct(string $id){parent::__construct("La medición {$id} ya está vinculada a otro contacto.",409);} }
