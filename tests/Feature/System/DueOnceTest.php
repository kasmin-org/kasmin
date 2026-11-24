<?php

namespace Tests\Feature\System;

use App\Support\System\DueOnce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DueOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_jobs_run_once_at_the_first_late_call(): void
    {
        $this->travelTo(now()->setTime(8, 56));
        $this->assertFalse(DueOnce::day('remind', '09:00'));
        $this->travelTo(now()->setTime(9, 3)); // no call at 09:00
        $this->assertTrue(DueOnce::day('remind', '09:00'));
        $this->assertFalse(DueOnce::day('remind', '09:00'));
        $this->assertTrue(DueOnce::day('digest', '07:00'), 'jobs are independent');
        $this->travelTo(now()->addDay()->setTime(9, 1));
        $this->assertTrue(DueOnce::day('remind', '09:00'));
    }

    public function test_hourly_jobs_run_once_per_hour(): void
    {
        $this->travelTo(now()->setTime(10, 2));
        $this->assertTrue(DueOnce::hour('automations'));
        $this->travelTo(now()->setTime(10, 57));
        $this->assertFalse(DueOnce::hour('automations'));
        $this->travelTo(now()->setTime(11, 3));
        $this->assertTrue(DueOnce::hour('automations'));
    }
}
