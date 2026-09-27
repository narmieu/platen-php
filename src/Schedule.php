<?php

declare(strict_types=1);

namespace App;

use App\Messenger\CleanUp;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function getSchedule(): SymfonySchedule
    {
        return new SymfonySchedule()->add(RecurringMessage::every('10 minutes', new CleanUp()));
    }
}
