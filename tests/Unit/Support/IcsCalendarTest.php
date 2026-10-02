<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\IcsCalendar;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class IcsCalendarTest extends TestCase
{
    public function test_it_writes_all_day_events_with_crlf_lines(): void
    {
        $ics = (new IcsCalendar('My plan'))
            ->addDay('w-1@test', new DateTimeImmutable('2026-10-31'), 'Bike: Long ride', 'Steady', new DateTimeImmutable('2026-10-02 08:00:00 UTC'))
            ->render();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261031\r\nDTEND;VALUE=DATE:20261101\r\n", $ics);
        $this->assertStringContainsString("DTSTAMP:20261002T080000Z\r\n", $ics);
        $this->assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);
    }

    public function test_text_is_escaped(): void
    {
        $this->assertSame('a\\, b\; c\\\\ d\\ne', IcsCalendar::escape("a, b; c\\ d\ne"));
    }

    public function test_long_lines_fold_at_75_octets_without_splitting_characters(): void
    {
        $line = 'SUMMARY:'.str_repeat('✓ ride ', 30);
        $folded = IcsCalendar::fold($line);

        foreach (explode("\r\n", $folded) as $part) {
            $this->assertLessThanOrEqual(75, strlen($part));
            $this->assertTrue(mb_check_encoding($part, 'UTF-8'));
        }
        $this->assertSame($line, str_replace("\r\n ", '', $folded));
    }
}
