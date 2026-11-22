<?php
namespace App\Modules\Finance\Contracts;
use App\Modules\Finance\Domain\Entities\FinancialTransaction;
interface LedgerRepositoryInterface{public function save(FinancialTransaction $transaction):void;}
