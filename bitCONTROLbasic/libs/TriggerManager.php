<?php

declare(strict_types=1);

class TriggerManager
{
    private const EVENT_PREFIX = 'BIT_';
    private const TIMING_EVENT_PREFIX = self::EVENT_PREFIX . 'Timing_';
    private int $instanceID;

    public function __construct(int $instanceID)
    {
        $this->instanceID = $instanceID;
    }

    public function applyTriggers(array $triggers): void
    {
        $this->removeOldManagedEvents();

        foreach ($triggers as $trigger) {
            $type = $trigger['type'] ?? 'event';
            if ($type === 'event') {
                $this->createEventTrigger($trigger);
            } elseif ($type === 'cyclic') {
                $this->createCyclicTrigger($trigger);
            }
        }
    }

    /**
     * Keeps one one-shot cyclic event per timed entry in sync with $schedule.
     *
     * A timestamp in the future is programmed as it is. A timestamp in the past means the
     * expiry was missed (module down, Symcon restart). It then fires exactly once: if the
     * event's `LastRun` is older than the expiry, the event is re-armed to `now + 2`;
     * if `LastRun` is at or after the expiry it has already fired for it and is only
     * switched off. Without that check every evaluation would re-arm the same past expiry
     * and the event would loop every two seconds.
     *
     * @param array<string, ?int> $schedule eventName => unix timestamp of the next
     *                                       occurrence, or null to deactivate.
     */
    public function syncTimingEvents(array $schedule): void
    {
        $existing = $this->getTimingEventsByName();

        foreach ($schedule as $name => $timestamp) {
            $eventID = $existing[$name] ?? 0;
            unset($existing[$name]);

            if ($eventID === 0) {
                $eventID = $this->createTimingEvent($name);
                if ($eventID === 0) {
                    continue;
                }
            }

            $this->applyTimingSchedule($eventID, $timestamp);
        }

        foreach ($existing as $eventID) {
            IPS_DeleteEvent($eventID);
        }
    }

    /** @return array<string, int> eventName => eventID for existing BIT_Timing_* events */
    private function getTimingEventsByName(): array
    {
        $events = [];
        foreach (IPS_GetChildrenIDs($this->instanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            $name = $obj['ObjectName'] ?? '';
            if (($obj['ObjectType'] ?? -1) === 4 && str_starts_with($name, self::TIMING_EVENT_PREFIX)) {
                $events[$name] = $childID;
            }
        }
        return $events;
    }

    private function createTimingEvent(string $name): int
    {
        $eventID = IPS_CreateEvent(1);
        if ($eventID === false) {
            IPS_LogMessage('bitCONTROL', sprintf('Instance %d: Failed to create timing event %s', $this->instanceID, $name));
            return 0;
        }

        IPS_SetParent($eventID, $this->instanceID);
        IPS_SetName($eventID, $name);
        IPS_SetHidden($eventID, true);
        IPS_SetEventScript($eventID, 'BIT_EvaluateTiming(' . $this->instanceID . ');');
        IPS_SetEventActive($eventID, false);

        return $eventID;
    }

    private function applyTimingSchedule(int $eventID, ?int $timestamp): void
    {
        $event = IPS_GetEvent($eventID);
        $active = (bool)($event['EventActive'] ?? false);

        if ($timestamp === null) {
            if ($active) {
                IPS_SetEventActive($eventID, false);
            }
            return;
        }

        $now = time();
        if ($timestamp <= $now) {
            // Verpasster Ablauf: nur einmal nachholen, nie erneut scharf schalten.
            if ((int)($event['LastRun'] ?? 0) >= $timestamp) {
                if ($active) {
                    IPS_SetEventActive($eventID, false);
                }
                return;
            }
            $timestamp = $now + 2;
        }

        $day    = (int)date('j', $timestamp);
        $month  = (int)date('n', $timestamp);
        $year   = (int)date('Y', $timestamp);
        $hour   = (int)date('G', $timestamp);
        $minute = (int)date('i', $timestamp);
        $second = (int)date('s', $timestamp);

        $dateFrom = $event['CyclicDateFrom'] ?? [];
        $timeFrom = $event['CyclicTimeFrom'] ?? [];

        $unchanged = $active
            && (int)($dateFrom['Day'] ?? -1) === $day
            && (int)($dateFrom['Month'] ?? -1) === $month
            && (int)($dateFrom['Year'] ?? -1) === $year
            && (int)($timeFrom['Hour'] ?? -1) === $hour
            && (int)($timeFrom['Minute'] ?? -1) === $minute
            && (int)($timeFrom['Second'] ?? -1) === $second;

        if ($unchanged) {
            return;
        }

        IPS_SetEventCyclic($eventID, 1, 0, 0, 0, 0, 0);
        IPS_SetEventCyclicDateFrom($eventID, $day, $month, $year);
        IPS_SetEventCyclicTimeFrom($eventID, $hour, $minute, $second);
        IPS_SetEventActive($eventID, true);
    }

    public function buildAliasMap(array $triggers): array
    {
        $map = [];
        foreach ($triggers as $trigger) {
            if (($trigger['type'] ?? 'event') !== 'event') {
                continue;
            }
            $alias      = $trigger['alias'] ?? '';
            $variableID = $trigger['variableID'] ?? 0;

            if ($alias === '' || $variableID === 0 || !IPS_VariableExists($variableID)) {
                continue;
            }

            $map[$alias] = GetValue($variableID);
        }
        return $map;
    }

    public function validateTriggers(array $triggers): array
    {
        $errors = [];

        if (empty($triggers)) {
            $errors[] = 'At least one trigger must be defined';
            return $errors;
        }

        foreach ($triggers as $i => $trigger) {
            $type = $trigger['type'] ?? 'event';

            if ($type === 'event') {
                $variableID = $trigger['variableID'] ?? 0;
                if ($variableID === 0) {
                    $errors[] = sprintf('Trigger %d: No variable selected', $i + 1);
                } elseif (!IPS_VariableExists($variableID)) {
                    $errors[] = sprintf('Trigger %d: Variable #%d does not exist', $i + 1, $variableID);
                }
                $eventType = $trigger['eventType'] ?? 1;
                if (in_array($eventType, [2, 3, 4], true) && empty($trigger['threshold'])) {
                    $errors[] = sprintf('Trigger %d: Threshold required for this trigger type', $i + 1);
                }
            }
        }

        return $errors;
    }

    public function getDeactivatedByLimit(): array
    {
        $deactivated = [];
        foreach (IPS_GetChildrenIDs($this->instanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 4 && str_starts_with($obj['ObjectName'], self::EVENT_PREFIX)) {
                if ($this->isTimingEventName($obj['ObjectName'])) {
                    continue;
                }
                $event = IPS_GetEvent($childID);
                if (!$event['EventActive'] && $event['EventLimit'] > 0) {
                    $alias = substr($obj['ObjectName'], strlen(self::EVENT_PREFIX));
                    $deactivated[$alias] = $event['EventLimit'];
                }
            }
        }
        return $deactivated;
    }

    private function isTimingEventName(string $name): bool
    {
        return str_starts_with($name, self::TIMING_EVENT_PREFIX);
    }

    public function removeAllEvents(): void
    {
        $this->removeOldManagedEvents();
    }

    private function removeOldManagedEvents(): void
    {
        foreach (IPS_GetChildrenIDs($this->instanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] === 4 && str_starts_with($obj['ObjectName'], self::EVENT_PREFIX)) {
                IPS_DeleteEvent($childID);
            }
        }
    }

    private function createEventTrigger(array $trigger): void
    {
        $variableID = $trigger['variableID'] ?? 0;
        $eventType  = (int)($trigger['eventType'] ?? 1);
        $threshold  = $trigger['threshold'] ?? null;
        $alias      = $trigger['alias'] ?? '';

        if ($variableID === 0 || !IPS_VariableExists($variableID)) {
            return;
        }

        $eventID = IPS_CreateEvent(0);
        if ($eventID === false) {
            IPS_LogMessage('bitCONTROL', sprintf(
                'Instance %d: Failed to create event trigger for variable #%d',
                $this->instanceID,
                $variableID
            ));
            return;
        }

        $name = self::EVENT_PREFIX . ($alias !== '' ? $alias : '#' . $variableID);
        IPS_SetParent($eventID, $this->instanceID);
        IPS_SetName($eventID, $name);
        IPS_SetHidden($eventID, true);
        IPS_SetEventTrigger($eventID, $eventType, $variableID);

        if ($threshold !== null && $threshold !== '' && in_array($eventType, [2, 3, 4], true)) {
            $varType = IPS_GetVariable($variableID)['VariableType'];
            $value = match ($varType) {
                0 => (bool)$threshold,
                1 => (int)$threshold,
                2 => (float)$threshold,
                default => (string)$threshold,
            };
            IPS_SetEventTriggerValue($eventID, $value);
        }

        $triggerRepeat = (int)($trigger['triggerRepeat'] ?? 0);
        if (in_array($eventType, [2, 3, 4], true)) {
            IPS_SetEventTriggerSubsequentExecution($eventID, $triggerRepeat === 1);
        }

        $triggerLimit = (int)($trigger['triggerLimit'] ?? 0);
        if ($triggerLimit > 0) {
            IPS_SetEventLimit($eventID, $triggerLimit);
        }

        $conditions = $trigger['triggerConditions'] ?? '[]';
        if (!empty($conditions) && $conditions !== '[]') {
            $this->applyEventConditions($eventID, $conditions);
        }

        IPS_SetEventScript($eventID, 'BIT_Evaluate(' . $this->instanceID . ');');
        IPS_SetEventActive($eventID, !empty($trigger['active']));
    }

    private function createCyclicTrigger(array $trigger): void
    {
        $dayPattern   = (int)($trigger['dayPattern'] ?? 0);
        $dayInterval  = (int)($trigger['dayInterval'] ?? 1);
        $monthOrdinal = (int)($trigger['monthOrdinal'] ?? 1);
        $monthDayType = (int)($trigger['monthDayType'] ?? 0);
        $yearDay      = (int)($trigger['yearDay'] ?? 1);
        $yearMonth    = (int)($trigger['yearMonth'] ?? 1);
        $specificDate = $this->dateToTimestamp($trigger['specificDate'] ?? 0);
        $dateFrom     = $this->dateToTimestamp($trigger['dateFrom'] ?? 0);
        $dateTo       = $this->dateToTimestamp($trigger['dateTo'] ?? 0);
        $unlimited    = (bool)($trigger['unlimited'] ?? true);
        $timePattern  = (int)($trigger['timePattern'] ?? 0);
        $timeValue    = $this->timeToSeconds($trigger['timeValue'] ?? 0);
        $timeInterval = (int)($trigger['timeInterval'] ?? 1);
        $timeFromSec  = $this->timeToSeconds($trigger['timeFrom'] ?? 0);
        $timeToSec    = $this->timeToSeconds($trigger['timeTo'] ?? 0);

        $weekdays = 0;
        if (!empty($trigger['wdMon'])) {
            $weekdays |= 1;
        }
        if (!empty($trigger['wdTue'])) {
            $weekdays |= 2;
        }
        if (!empty($trigger['wdWed'])) {
            $weekdays |= 4;
        }
        if (!empty($trigger['wdThu'])) {
            $weekdays |= 8;
        }
        if (!empty($trigger['wdFri'])) {
            $weekdays |= 16;
        }
        if (!empty($trigger['wdSat'])) {
            $weekdays |= 32;
        }
        if (!empty($trigger['wdSun'])) {
            $weekdays |= 64;
        }

        $ipsTimeType     = $timePattern;
        $ipsTimeInterval = ($timePattern > 0) ? $timeInterval : 0;

        $eventID = IPS_CreateEvent(1);
        if ($eventID === false) {
            IPS_LogMessage('bitCONTROL', sprintf(
                'Instance %d: Failed to create cyclic event trigger',
                $this->instanceID
            ));
            return;
        }

        IPS_SetParent($eventID, $this->instanceID);
        IPS_SetName($eventID, self::EVENT_PREFIX . 'Cyclic');
        IPS_SetHidden($eventID, true);

        switch ($dayPattern) {
            case 0:
                IPS_SetEventCyclic($eventID, 2, $dayInterval, 0, 0, $ipsTimeType, $ipsTimeInterval);
                break;
            case 1:
                IPS_SetEventCyclic($eventID, 3, $dayInterval, $weekdays ?: 127, 0, $ipsTimeType, $ipsTimeInterval);
                break;
            case 2:
                IPS_SetEventCyclic($eventID, 4, $dayInterval, $monthDayType, $monthOrdinal, $ipsTimeType, $ipsTimeInterval);
                break;
            case 3:
                IPS_SetEventCyclic($eventID, 5, 1, $yearDay, $yearMonth, $ipsTimeType, $ipsTimeInterval);
                break;
            case 4:
                IPS_SetEventCyclic($eventID, 1, 0, 0, 0, $ipsTimeType, $ipsTimeInterval);
                if ($specificDate > 0) {
                    $d = (int)date('d', $specificDate);
                    $m = (int)date('m', $specificDate);
                    $y = (int)date('Y', $specificDate);
                    IPS_SetEventCyclicDateFrom($eventID, $d, $m, $y);
                    IPS_SetEventCyclicDateTo($eventID, $d, $m, $y);
                }
                break;
        }

        if ($dayPattern !== 4 && $dateFrom > 0) {
            IPS_SetEventCyclicDateFrom($eventID, (int)date('d', $dateFrom), (int)date('m', $dateFrom), (int)date('Y', $dateFrom));
            if (!$unlimited && $dateTo > 0) {
                IPS_SetEventCyclicDateTo($eventID, (int)date('d', $dateTo), (int)date('m', $dateTo), (int)date('Y', $dateTo));
            } else {
                IPS_SetEventCyclicDateTo($eventID, 0, 0, 0);
            }
        }

        if ($timePattern === 0 && $timeValue > 0) {
            IPS_SetEventCyclicTimeFrom($eventID, (int)($timeValue / 3600), (int)(($timeValue % 3600) / 60), $timeValue % 60);
            IPS_SetEventCyclicTimeTo($eventID, 0, 0, 0);
        } elseif ($timePattern > 0) {
            if ($timeFromSec !== $timeToSec && ($timeFromSec > 0 || $timeToSec > 0)) {
                IPS_SetEventCyclicTimeFrom($eventID, (int)($timeFromSec / 3600), (int)(($timeFromSec % 3600) / 60), $timeFromSec % 60);
                IPS_SetEventCyclicTimeTo($eventID, (int)($timeToSec / 3600), (int)(($timeToSec % 3600) / 60), $timeToSec % 60);
            }
        }

        IPS_SetEventScript($eventID, 'BIT_Evaluate(' . $this->instanceID . ');');
        IPS_SetEventActive($eventID, !empty($trigger['active']));
    }

    private function dateToTimestamp(mixed $value): int
    {
        if (is_string($value) && str_starts_with(trim($value), '{')) {
            $value = json_decode($value, true) ?? [];
        }
        if (is_array($value)) {
            $y = (int)($value['year'] ?? 1970);
            $m = (int)($value['month'] ?? 1);
            $d = (int)($value['day'] ?? 1);
            return $y > 1970 ? mktime(0, 0, 0, $m, $d, $y) : 0;
        }
        return (int)$value;
    }

    private function timeToSeconds(mixed $value): int
    {
        if (is_string($value) && str_starts_with(trim($value), '{')) {
            $value = json_decode($value, true) ?? [];
        }
        if (is_array($value)) {
            $h = (int)($value['hour'] ?? 0);
            $m = (int)($value['minute'] ?? 0);
            $s = (int)($value['second'] ?? 0);
            return $h * 3600 + $m * 60 + $s;
        }
        return (int)$value;
    }

    public function getMaxTriggers(): int
    {
        $limiter = ProLoader::get('limiter');
        if ($limiter) {
            return $limiter->getLimit('triggers');
        }
        return $this->getCommunityLimits()['triggers'];
    }

    public function isAtLimit(array $triggers): bool
    {
        return count($triggers) >= $this->getMaxTriggers();
    }

    private function getCommunityLimits(): array
    {
        static $limits = null;
        if ($limits === null) {
            $limits = json_decode(file_get_contents(__DIR__ . '/limits.json'), true);
        }
        return $limits;
    }

    private function applyEventConditions(int $eventID, mixed $conditionsData): void
    {
        if (is_string($conditionsData)) {
            $conditions = json_decode($conditionsData, true);
        } else {
            $conditions = $conditionsData;
        }
        if (!is_array($conditions) || empty($conditions)) {
            return;
        }

        foreach ($conditions as $condition) {
            $conditionID = (int)($condition['id'] ?? 0);
            $parentID    = (int)($condition['parentID'] ?? 0);
            $operation   = (int)($condition['operation'] ?? 0);

            IPS_SetEventCondition($eventID, $conditionID, $parentID, $operation);

            $rules = $condition['rules'] ?? [];

            foreach ($rules['variable'] ?? [] as $rule) {
                $ruleID     = (int)($rule['id'] ?? 0);
                $variableID = (int)($rule['variableID'] ?? 0);
                $comparison = (int)($rule['comparison'] ?? 0);
                $value      = $rule['value'] ?? 0;
                $type       = (int)($rule['type'] ?? 0);

                if ($variableID === 0) {
                    continue;
                }

                if ($type === 1) {
                    IPS_SetEventConditionVariableDynamicRule($eventID, $conditionID, $ruleID, $variableID, $comparison, (int)$value);
                } else {
                    IPS_SetEventConditionVariableRule($eventID, $conditionID, $ruleID, $variableID, $comparison, $value);
                }
            }

            foreach ($rules['time'] ?? [] as $rule) {
                $ruleID     = (int)($rule['id'] ?? 0);
                $comparison = (int)($rule['comparison'] ?? 0);
                $time       = $rule['value'] ?? [];
                $hour       = (int)($time['hour'] ?? $time['Hour'] ?? 0);
                $minute     = (int)($time['minute'] ?? $time['Minute'] ?? 0);
                $second     = (int)($time['second'] ?? $time['Second'] ?? 0);

                IPS_SetEventConditionTimeRule($eventID, $conditionID, $ruleID, $comparison, $hour, $minute, $second);
            }

            foreach ($rules['dayOfTheWeek'] ?? [] as $rule) {
                $ruleID     = (int)($rule['id'] ?? 0);
                $comparison = (int)($rule['comparison'] ?? 0);
                $value      = (int)($rule['value'] ?? 0);

                IPS_SetEventConditionDayOfTheWeekRule($eventID, $conditionID, $ruleID, $comparison, $value);
            }

            foreach ($rules['date'] ?? [] as $rule) {
                $ruleID     = (int)($rule['id'] ?? 0);
                $comparison = (int)($rule['comparison'] ?? 0);
                $date       = $rule['value'] ?? [];
                $day        = (int)($date['day'] ?? $date['Day'] ?? 0);
                $month      = (int)($date['month'] ?? $date['Month'] ?? 0);
                $year       = (int)($date['year'] ?? $date['Year'] ?? 0);

                IPS_SetEventConditionDateRule($eventID, $conditionID, $ruleID, $comparison, $day, $month, $year);
            }
        }
    }
}
