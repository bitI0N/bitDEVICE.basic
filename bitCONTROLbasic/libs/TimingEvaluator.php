<?php

declare(strict_types=1);

class TimingEvaluator
{
    /** @var callable(string): int */
    private $readState;

    /** @var callable(string, int): void */
    private $writeState;

    public function __construct(callable $readState, callable $writeState)
    {
        $this->readState = $readState;
        $this->writeState = $writeState;
    }

    public function checkHeatup(string $stateKey, int $delaySeconds): string
    {
        if ($delaySeconds <= 0) {
            return 'passed';
        }

        $key = 'HeatupStart_' . $stateKey;
        $startTime = ($this->readState)($key);

        if ($startTime === 0) {
            ($this->writeState)($key, time());
            return 'started';
        }

        if ((time() - $startTime) < $delaySeconds) {
            return 'waiting';
        }

        return 'passed';
    }

    public function cancelHeatup(string $stateKey, bool $resetOnInterruption = true): void
    {
        if ($resetOnInterruption) {
            ($this->writeState)('HeatupStart_' . $stateKey, 0);
        }
    }

    public function checkCooldown(string $stateKey, int $cooldownSeconds): string
    {
        if ($cooldownSeconds <= 0) {
            return 'expired';
        }

        if (($this->readState)('WasActive_' . $stateKey) !== 1) {
            return 'expired';
        }

        $key = 'CooldownStart_' . $stateKey;
        $startTime = ($this->readState)($key);

        if ($startTime === 0) {
            ($this->writeState)($key, time());
            return 'started';
        }

        if ((time() - $startTime) < $cooldownSeconds) {
            return 'active';
        }

        return 'expired';
    }

    public function markActive(string $stateKey, bool $resetCooldownOnReactivation = true): void
    {
        ($this->writeState)('WasActive_' . $stateKey, 1);
        if ($resetCooldownOnReactivation) {
            ($this->writeState)('CooldownStart_' . $stateKey, 0);
        }
    }

    public function markInactive(string $stateKey): void
    {
        ($this->writeState)('WasActive_' . $stateKey, 0);
        ($this->writeState)('CooldownStart_' . $stateKey, 0);
    }

    public function checkInterval(string $stateKey, int $intervalSeconds): bool
    {
        if ($intervalSeconds <= 0) {
            return true;
        }

        $key = 'LastRun_' . $stateKey;
        $lastRun = ($this->readState)($key);

        if ($lastRun === 0) {
            return true;
        }

        return (time() - $lastRun) >= $intervalSeconds;
    }

    public function markLastRun(string $stateKey): void
    {
        ($this->writeState)('LastRun_' . $stateKey, time());
    }

    public function pruneState(array $validKeys, callable $getAllKeys): void
    {
        $prefixes = ['HeatupStart_', 'WasActive_', 'CooldownStart_', 'LastRun_', 'DelayStart_'];
        $allKeys = $getAllKeys();

        foreach ($allKeys as $key) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $stateKey = substr($key, strlen($prefix));
                    if (!in_array($stateKey, $validKeys, true)) {
                        ($this->writeState)($key, 0);
                    }
                    break;
                }
            }
        }
    }

    public static function shouldSkipTiming(int $ruleIndex): bool
    {
        $timing = ProLoader::get('timing');
        return $timing !== null && $timing->isSkippable($ruleIndex);
    }
}
