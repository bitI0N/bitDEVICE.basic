<?php

declare(strict_types=1);

final class TimingSchedule
{
    /** @return ?int unix timestamp of the next phase end for this entry, null when no phase runs or timer disabled */
    public static function next(array $entry, string $stateKey, array $state, int $now): ?int
    {
        if (empty($entry['timerEnabled'])) {
            return null;
        }

        $cooldown = (int) ($entry['cooldownSeconds'] ?? 0) * (int) ($entry['cooldownUnit'] ?? 1);
        $heatup = (int) ($entry['delaySeconds'] ?? 0) * (int) ($entry['delayUnit'] ?? 1);

        $cooldownStart = (int) ($state['CooldownStart_' . $stateKey] ?? 0);
        $heatupStart = (int) ($state['HeatupStart_' . $stateKey] ?? 0);
        $heatupDone = (int) ($state['HeatupDone_' . $stateKey] ?? 0);

        $end = null;
        if ($cooldownStart > 0 && $cooldown > 0) {
            $end = $cooldownStart + $cooldown;
        } elseif ($heatupStart > 0 && $heatupDone !== 1 && $heatup > 0) {
            $end = $heatupStart + $heatup;
        }

        if ($end === null) {
            return null;
        }

        if ($end <= $now) {
            return $now + 2;
        }

        return $end;
    }

    public static function eventName(string $key): string
    {
        return 'BIT_Timing_' . $key;
    }
}
