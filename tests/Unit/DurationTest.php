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

    public function test_hours_and_minutes_typed_into_a_form_become_minutes(): void
    {
        $this->assertSame(
            [0, 45, 120, 150, 150],
            [Duration::fromParts(null, null), Duration::fromParts('', '45'), Duration::fromParts('2', null), Duration::fromParts('2', '30'), Duration::fromParts(2, 30)],
        );
    }
}
