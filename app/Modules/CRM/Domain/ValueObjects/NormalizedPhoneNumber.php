<?php
namespace App\Modules\CRM\Domain\ValueObjects;
use InvalidArgumentException;
readonly class NormalizedPhoneNumber
{
 private string $normalized;
 public function __construct(string $raw,string $countryDialCode='52'){$clean=preg_replace('/\D+/','',$raw);$code=preg_replace('/\D+/','',$countryDialCode);if(!is_string($clean)||!is_string($code)||$clean===''||$code==='')throw new InvalidArgumentException('Número de teléfono inválido.');if(strlen($clean)===10)$clean=$code.$clean;if(strlen($clean)<8||strlen($clean)>15)throw new InvalidArgumentException('Número de teléfono fuera de rango.');$this->normalized=$clean;}
 public function getValue():string{return $this->normalized;} public function equals(self $o):bool{return $this->normalized===$o->normalized;} public function __toString():string{return $this->normalized;}
}
