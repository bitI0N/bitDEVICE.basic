<?php

declare(strict_types=1);

class RuleEvaluator
{
    private int $instanceID;
    private TimingEvaluator $timing;

    public function __construct(int $instanceID, callable $readState, callable $writeState)
    {
        $this->instanceID = $instanceID;
        $this->timing = new TimingEvaluator($readState, $writeState);
    }

    public function evaluate(array $rules, int $evaluationMode, bool $skipHeatup = false, bool $skipCooldown = false, bool $skipInterval = false): ?string
    {
        $isFirstMatch = $evaluationMode === 0;

        $activeRules = array_filter($rules, static fn(array $rule) => !empty($rule['active']));
        usort($activeRules, static fn(array $a, array $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $activeRuleName = null;

        foreach ($activeRules as $index => $rule) {
            $stateKey = self::ruleKey($rule, $index);
            $conditionsMet = $this->checkConditions($rule);

            $heatupReset   = (bool)($rule['heatupResetOnInterruption'] ?? true);
            $cooldownReset = (bool)($rule['cooldownResetOnReactivation'] ?? true);

            if ($conditionsMet) {
                $delaySeconds = (int)($rule['delaySeconds'] ?? 0) * (int)($rule['delayUnit'] ?? 1);

                $heatupStatus = $this->timing->checkHeatup($stateKey, $delaySeconds);
                if ($heatupStatus !== 'passed') {
                    if ($isFirstMatch && !$skipHeatup) {
                        return ($rule['name'] ?? '') . ' (heatup)';
                    }
                    $activeRuleName = ($rule['name'] ?? '') . ' (heatup)';
                    continue;
                }

                $intervalSeconds = (int)($rule['intervalSeconds'] ?? 0) * (int)($rule['intervalUnit'] ?? 1);
                if (!$this->timing->checkInterval($stateKey, $intervalSeconds)) {
                    if ($isFirstMatch && !$skipInterval) {
                        return null;
                    }
                    continue;
                }

                $this->executeActions($rule);
                $this->timing->markActive($stateKey, $cooldownReset);
                $this->timing->markLastRun($stateKey);

                if ($isFirstMatch) {
                    return $rule['name'] ?? null;
                }

                $activeRuleName = $rule['name'] ?? null;
            } else {
                $this->timing->cancelHeatup($stateKey, $heatupReset);

                $cooldownSeconds = (int)($rule['cooldownSeconds'] ?? 0) * (int)($rule['cooldownUnit'] ?? 1);
                $cooldownStatus = $this->timing->checkCooldown($stateKey, $cooldownSeconds);

                if ($cooldownStatus === 'started' || $cooldownStatus === 'active') {
                    if ($skipCooldown) {
                        continue;
                    }
                    if ($isFirstMatch) {
                        return ($rule['name'] ?? '') . ' (cooldown)';
                    }
                    $activeRuleName = ($rule['name'] ?? '') . ' (cooldown)';
                    continue;
                }

                if ($cooldownStatus === 'expired') {
                    $this->timing->markInactive($stateKey);
                    if ($this->executeFallbackActions($rule)) {
                        if ($isFirstMatch) {
                            return ($rule['name'] ?? '') . ' (fallback)';
                        }
                        $activeRuleName = ($rule['name'] ?? '') . ' (fallback)';
                    }
                }
            }
        }

        return $activeRuleName;
    }

    public static function ruleKey(array $rule, int $index = -1): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $rule['name'] ?? 'rule');
        return $index >= 0 ? $name . '_' . $index : $name;
    }

    private function checkConditions(array $rule): bool
    {
        $conditions = $rule['conditions'] ?? '';

        if ($conditions === '' || $conditions === '[]') {
            return true;
        }

        try {
            return IPS_IsConditionPassing($conditions);
        } catch (\Throwable $e) {
            IPS_LogMessage('bitCONTROL', sprintf(
                'Instance %d: Condition check failed for rule "%s": %s',
                $this->instanceID,
                $rule['name'] ?? 'unknown',
                $e->getMessage()
            ));

            return false;
        }
    }

    private function executeActions(array $rule): void
    {
        $this->runActions($rule['actions'] ?? [], $rule['name'] ?? 'unknown');
    }

    private function executeFallbackActions(array $rule): bool
    {
        if (empty($rule['fallbackEnabled'])) {
            return false;
        }
        $fallback = $rule['fallbackActions'] ?? [];
        if (empty($fallback) || $fallback === '{}' || $fallback === '[]') {
            return false;
        }
        $this->runActions($fallback, ($rule['name'] ?? 'unknown') . ' [fallback]');
        return true;
    }

    private function runActions(mixed $actions, string $context): void
    {
        if (is_string($actions)) {
            $decoded = json_decode($actions, true);
            if ($decoded !== null) {
                $actions = isset($decoded['actionID']) ? [$decoded] : $decoded;
            } else {
                $actions = [];
            }
        }

        foreach ((array)$actions as $action) {
            try {
                IPS_RunAction($action['actionID'], $action['parameters'] ?? []);
            } catch (\Throwable $e) {
                IPS_LogMessage('bitCONTROL', sprintf(
                    'Instance %d: Action execution failed for "%s": %s',
                    $this->instanceID,
                    $context,
                    $e->getMessage()
                ));
            }
        }
    }
}
