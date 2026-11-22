<?php
namespace App\Modules\Sales\Contracts;
use App\Modules\Sales\Domain\Entities\Sale;
interface SaleRepositoryInterface{public function find(string $id):?Sale;/** @return array */public function findItems(string $saleId):array;}
