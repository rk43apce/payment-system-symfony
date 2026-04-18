<?php

namespace App\Enum;

enum TransferStatus: string
{
    case COMPLETED = 'COMPLETED';
    case FAILED    = 'FAILED';
}
