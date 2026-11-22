<?php
namespace App\Modules\IoT\Domain\Exceptions;
use RuntimeException;
final class DuplicateMeasurementException extends RuntimeException { public function __construct(string $id){parent::__construct("La medición {$id} ya fue registrada.",409);} }
