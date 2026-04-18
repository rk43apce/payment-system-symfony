<?php

namespace App\Enum;

enum LedgerReferenceType: string
{
    case TOP_UP   = 'TOP_UP';
    case TRANSFER = 'TRANSFER';
}
