<?php
namespace App\Modules\Inventory\Contracts;
use App\Modules\Inventory\Domain\Entities\InventoryTransaction;
interface InventoryTransactionRepositoryInterface{public function save(InventoryTransaction $transaction):void;}
