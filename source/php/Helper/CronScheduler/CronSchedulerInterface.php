<?php

namespace ApiSponsorManager\Helper\CronScheduler;

interface CronSchedulerInterface
{
    public function addEvent(CronEventInterface $cronEvent): void;
}
