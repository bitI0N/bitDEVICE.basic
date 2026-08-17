<?php

declare(strict_types=1);

final class TimingSchedule
{
    /**
     * Unix timestamp of the running phase's end, null when no phase runs or the timer is off.
     *
     * A missed end is returned as it is — in the past. Deciding whether that expiry still has to
     * fire is TriggerManager's job (it compares the event's LastRun); clamping it here would
     * re-arm an already handled expiry on every single evaluation.
     *
     * @param int $now unused for the result, kept so callers keep passing an explicit clock
     */
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

        return $end;
    }

    public static function eventName(string $key): string
    {
        return 'BIT_Timing_' . $key;
    }
}
