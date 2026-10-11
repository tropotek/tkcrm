<?php

namespace Tests\Unit;

use App\Support\Duration;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public function test_minutes_are_written_as_hours_and_minutes(): void
    {
        $this->assertSame(
            ['0m', '1m', '45m', '1h', '1h 1m', '8h 30m', '100h', '-1h 30m'],
            array_map(Duration::format(...), [0, 1, 45, 60, 61, 510, 6000, -90]),
        );
    }
}
