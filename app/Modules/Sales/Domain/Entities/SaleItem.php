<?php
namespace App\Modules\Sales\Domain\Entities;
readonly class SaleItem
{public function __construct(public string $id,public string $saleId,public string $productId,public int $quantity,public string $productNameSnapshot,public int $unitPriceMinorSnapshot,public int $unitCostMinorSnapshot,public int $subtotalRevenueMinor,public int $subtotalProfitMinor){}public static function createFromProduct(string $saleId,string $id,Product $p,int $quantity):self{if($quantity<1)throw new \InvalidArgumentException('Cantidad inválida.');$revenue=$p->getBasePriceMinor()*$quantity;$profit=($p->getBasePriceMinor()-$p->getBaseCostMinor())*$quantity;return new self($id,$saleId,$p->getId(),$quantity,$p->getName(),$p->getBasePriceMinor(),$p->getBaseCostMinor(),$revenue,$profit);}}
