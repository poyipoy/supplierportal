<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/** Presentation only: accepts typed instants, calendar dates and trusted numeric display text. */
class RegionalDisplayFormatter
{
    private array $preferences;

    private array $registry;

    public function __construct(array $preferences)
    {
        $this->registry = config('regional_display');
        $this->preferences = [];
        foreach (['timezone' => 'timezones', 'date_format' => 'date_formats', 'time_format' => 'time_formats', 'number_format' => 'number_formats'] as $field => $registry) {
            $value = $preferences[$field] ?? null;
            $this->preferences[$field] = is_string($value) && isset($this->registry[$registry][$value])
                ? $value : config('user_preferences.defaults.'.$field);
        }
    }

    public function date(string|DateTimeInterface $value, string $profile = 'human'): string
    {
        $pattern = $this->registry['date_profiles'][$profile] ?? throw new InvalidArgumentException('Unknown calendar date presentation profile.');
        // DATE semantics preserve the source calendar day, including when given a model's Carbon date cast.
        $date = $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : $this->calendarDate($value);

        return $date === null ? (string) $value : $date->format($this->datePattern($pattern));
    }

    public function timestamp(DateTimeInterface $value, string $profile = 'datetime'): string
    {
        $patterns = $this->registry['timestamp_profiles'][$profile] ?? throw new InvalidArgumentException('Unknown timestamp presentation profile.');
        $instant = $this->instant($value);
        $text = $instant->format($this->datePattern($patterns['date']));
        if ($patterns['time'] !== null) {
            $text .= $patterns['separator'].$instant->format($this->timePattern($patterns['time']));
        }

        return $text.$this->zoneLabel();
    }

    public function time(DateTimeInterface $value): string
    {
        return $this->instant($value)->format($this->timePattern('H:i')).$this->zoneLabel();
    }

    /** Transcode separators only; no float conversion, rounding or fractional digit changes. */
    public function number(string $text, string $profile = 'international'): string
    {
        $target = $this->preferences['number_format'];
        if ($target === 'system') {
            return $text;
        }

        $source = $this->registry['number_profiles'][$profile] ?? throw new InvalidArgumentException('Unknown numeric presentation profile.');
        $destination = $this->registry['number_profiles'][$target];
        $decimal = preg_quote($source['decimal'], '/');
        $group = preg_quote($source['group'], '/');
        $integerPattern = $source['group'] === '' ? '[0-9]+' : '(?:[0-9]+|[0-9]{1,3}(?:'.$group.'[0-9]{3})+)';
        if (! preg_match('/^(\s*)([+-]?)('.$integerPattern.')(?:'.$decimal.'([0-9]+))?(\s*)$/D', $text, $parts)) {
            return $text;
        }
        $integer = $source['group'] === '' ? $parts[3] : str_replace($source['group'], '', $parts[3]);
        $grouped = preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', $destination['group'], $integer);
        $fraction = isset($parts[4]) && $parts[4] !== '' ? $destination['decimal'].$parts[4] : '';

        return $parts[1].$parts[2].$grouped.$fraction.($parts[5] ?? '');
    }

    private function calendarDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (! $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    private function instant(DateTimeInterface $value): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromInterface($value);

        return $this->preferences['timezone'] === 'system'
            ? $instant : $instant->setTimezone(new DateTimeZone($this->preferences['timezone']));
    }

    private function datePattern(string $legacy): string
    {
        return $this->registry['date_formats'][$this->preferences['date_format']]['format'] ?? $legacy;
    }

    private function timePattern(string $legacy): string
    {
        return $this->registry['time_formats'][$this->preferences['time_format']]['format'] ?? $legacy;
    }

    private function zoneLabel(): string
    {
        $label = $this->registry['timezones'][$this->preferences['timezone']]['zone_label'] ?? '';

        return $label === '' ? '' : ' '.$label;
    }
}
