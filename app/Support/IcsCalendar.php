<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;

/**
 * A minimal iCalendar (RFC 5545) writer for all-day events, enough for a
 * read-only feed that calendar apps subscribe to.
 */
final class IcsCalendar
{
    /** Content lines longer than this many octets are folded. */
    private const int LINE_OCTETS = 75;

    /** @var list<string> */
    private array $events = [];

    public function __construct(
        private readonly string $name,
        private readonly string $prodId = '-//TriTrain//Training plan//EN',
    ) {}

    /**
     * Adds an all-day event.
     */
    public function addDay(string $uid, DateTimeInterface $date, string $summary, string $description, DateTimeInterface $updated): self
    {
        $this->events[] = implode("\r\n", array_map(self::fold(...), [
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.gmdate('Ymd\THis\Z', $updated->getTimestamp()),
            'DTSTART;VALUE=DATE:'.$date->format('Ymd'),
            'DTEND;VALUE=DATE:'.(clone $date)->modify('+1 day')->format('Ymd'),
            'SUMMARY:'.self::escape($summary),
            'DESCRIPTION:'.self::escape($description),
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ]));

        return $this;
    }

    public function render(): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.$this->prodId,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            self::fold('X-WR-CALNAME:'.self::escape($this->name)),
            // Ask calendar apps to refresh every few hours, as the plan adapts.
            'REFRESH-INTERVAL;VALUE=DURATION:PT4H',
            'X-PUBLISHED-TTL:PT4H',
            ...$this->events,
            'END:VCALENDAR',
        ])."\r\n";
    }

    public static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], $text);
    }

    /**
     * Splits a content line into 75-octet pieces, never inside a UTF-8 character.
     */
    public static function fold(string $line): string
    {
        $parts = [];
        $current = '';

        foreach (mb_str_split($line) as $char) {
            $limit = $parts === [] ? self::LINE_OCTETS : self::LINE_OCTETS - 1;
            if (strlen($current) + strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
