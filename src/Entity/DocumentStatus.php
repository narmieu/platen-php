<?php

declare(strict_types=1);

namespace App\Entity;

enum DocumentStatus: string
{
    case Queued = 'queued';
    case Working = 'working';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
