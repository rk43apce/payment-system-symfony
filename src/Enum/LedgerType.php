<?php

namespace App\Enum;

enum LedgerType: string
{
    case CREDIT = 'CREDIT';
    case DEBIT  = 'DEBIT';
}
