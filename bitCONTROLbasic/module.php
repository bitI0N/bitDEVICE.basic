<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/ProLoader.php';
require_once __DIR__ . '/libs/AliasValidator.php';
require_once __DIR__ . '/libs/TimingEvaluator.php';
require_once __DIR__ . '/libs/RuleEvaluator.php';
require_once __DIR__ . '/libs/TriggerManager.php';
require_once __DIR__ . '/libs/FormBuilder.php';

class bitCONTROL extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyInteger('Mode', 0);
        $this->RegisterPropertyString('Triggers', '[]');
        $this->RegisterPropertyString('Rules', '[]');
        $this->RegisterPropertyInteger('RuleEvaluation', 0);
        $this->RegisterPropertyBoolean('RuleSkipHeatup', false);
        $this->RegisterPropertyBoolean('RuleSkipCooldown', false);
        $this->RegisterPropertyBoolean('RuleSkipInterval', false);
        $this->RegisterPropertyString('FormulaOutputs', '[]');
        $this->RegisterPropertyInteger('FormulaEvaluation', 1);
        $this->RegisterPropertyBoolean('FormulaSkipHeatup', false);
        $this->RegisterPropertyBoolean('FormulaSkipCooldown', false);
        $this->RegisterPropertyBoolean('FormulaSkipInterval', false);
        $this->RegisterPropertyString('ExpertScript', '');
        $this->RegisterPropertyString('ExpertOutputs', '[]');
        $this->RegisterPropertyInteger('ExpertInterval', 0);
        $this->RegisterPropertyInteger('ExpertIntervalUnit', 1);

        // Attributes
        $this->RegisterAttributeString('RuleState', '{}');
        $this->RegisterAttributeString('FormulaState', '{}');
        $this->RegisterAttributeString('ExpertLastRun', '0');

        // Timer
        $this->RegisterTimer('EvaluationTimer', 0, 'BIT_Evaluate($_IPS[\'TARGET\']);');

        // Variables
        $this->RegisterVariableBoolean('Active', $this->Translate('Active'), '~Switch', 0);
        $this->RegisterVariableString('ActiveRule', $this->Translate('Active Rule'), '', 1);
        $this->RegisterVariableInteger('LastEvaluation', $this->Translate('Last Evaluation'), '~UnixTimestamp', 2);

        $this->EnableAction('Active');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->ensureProLoader();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('Active', false);
            (new TriggerManager($this->InstanceID))->removeAllEvents();
            $this->SetStatus(104);
            return;
        }

        $triggers = $this->getAllTriggers();
        $mode = $this->ReadPropertyInteger('Mode');

        $triggerManager = new TriggerManager($this->InstanceID);
        $triggerErrors = $triggerManager->validateTriggers($triggers);
        if (!empty($triggerErrors)) {
            $this->SetStatus(200);
            return;
        }

        if (count($triggers) > $triggerManager->getMaxTriggers()) {
            $this->SetStatus(206);
            $this->SendDebug('LimitCheck', 'Trigger limit exceeded', 0);
            return;
        }

        if ($mode === 1 && !ProLoader::has('formula')) {
            $this->SetStatus(208);
            $this->SendDebug('LimitCheck', 'Formula mode requires Plus license', 0);
            return;
        }

        if ($mode === 2 && !ProLoader::has('expert')) {
            $this->SetStatus(209);
            $this->SendDebug('LimitCheck', 'Expert mode requires Pro license', 0);
            return;
        }

        $outputs = $this->getAllOutputs($mode);
        $aliasErrors = AliasValidator::validateAll($triggers, $outputs);
        if (!empty($aliasErrors)) {
            $this->SetStatus(201);
            $this->SendDebug('AliasValidation', implode('; ', $aliasErrors), 0);
            return;
        }

        $allAliases = $this->collectAllAliases($triggers, $outputs);

        if ($mode === 0) {
            $rules = json_decode($this->ReadPropertyString('Rules'), true) ?: [];
            if (count($rules) > RuleEvaluator::getMaxRules()) {
                $this->SetStatus(207);
                $this->SendDebug('LimitCheck', 'Rule limit exceeded', 0);
                return;
            }
            $ruleNames = [];
            foreach ($rules as $rule) {
                $name = $rule['name'] ?? '';
                if ($name !== '' && in_array($name, $ruleNames, true)) {
                    $this->SetStatus(205);
                    $this->SendDebug('RuleValidation', 'Duplicate rule name: ' . $name, 0);
                    return;
                }
                if ($name !== '') {
                    $ruleNames[] = $name;
                }
            }
        }

        if ($mode === 1 && ProLoader::has('formula')) {
            $formulaProvider = ProLoader::get('formula');
            $formulaOutputs = json_decode($this->ReadPropertyString('FormulaOutputs'), true) ?: [];
            foreach ($formulaOutputs as $output) {
                $formula = $output['formula'] ?? '';
                if ($formula === '') {
                    continue;
                }
                $error = $formulaProvider->validate($formula, $allAliases);
                if ($error !== '') {
                    $this->SetStatus(202);
                    $this->SendDebug('FormulaValidation', $error, 0);
                    return;
                }
                $fallbackFormula = !empty($output['fallbackFormulaEnabled']) ? ($output['fallbackFormula'] ?? '') : '';
                if ($fallbackFormula !== '') {
                    $error = $formulaProvider->validate($fallbackFormula, $allAliases);
                    if ($error !== '') {
                        $this->SetStatus(202);
                        $this->SendDebug('FallbackFormulaValidation', $error, 0);
                        return;
                    }
                }
            }
        }

        if ($mode === 2 && ProLoader::has('expert')) {
            $expertProvider = ProLoader::get('expert');
            $script = $this->ReadPropertyString('ExpertScript');
            if ($script !== '') {
                $outputAliases = array_filter(array_map(
                    static fn(array $o) => $o['alias'] ?? '',
                    $outputs
                ), static fn(string $a) => $a !== '');
                $error = $expertProvider->validate($script, $outputAliases);
                if ($error !== '') {
                    $this->SetStatus(203);
                    $this->SendDebug('ExpertValidation', $error, 0);
                    return;
                }
            }
        }

        $triggerManager->applyTriggers($triggers);

        foreach ($triggers as $trigger) {
            $variableID = $trigger['variableID'] ?? 0;
            if ($variableID > 0 && IPS_VariableExists($variableID)) {
                $this->RegisterReference($variableID);
            }
        }

        foreach ($outputs as $output) {
            $variableID = $output['variableID'] ?? 0;
            if ($variableID > 0) {
                $this->RegisterReference($variableID);
            }
        }

        $this->pruneOrphanedState($mode);

        $modeNames = [0 => 'Rule', 1 => 'Formula', 2 => 'Expert'];
        $this->SetSummary($modeNames[$mode] ?? 'Unknown');
        $this->SetValue('Active', true);
        $this->SetStatus(102);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Active':
                $this->SetValue('Active', $Value);
                IPS_SetProperty($this->InstanceID, 'Active', $Value);
                IPS_ApplyChanges($this->InstanceID);
                break;
        }
    }

    public function Evaluate(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }

        $triggers = $this->getAllTriggers();
        $triggerManager = new TriggerManager($this->InstanceID);
        $aliasMap = $triggerManager->buildAliasMap($triggers);

        $this->ensureProLoader();
        $mode = $this->ReadPropertyInteger('Mode');
        $result = match ($mode) {
            0 => $this->evaluateRules(),
            1 => ProLoader::has('formula') ? $this->evaluateFormulas($aliasMap) : $this->featureUnavailable('Formula', 'Plus'),
            2 => ProLoader::has('expert') ? $this->evaluateExpert($aliasMap) : $this->featureUnavailable('Expert', 'Pro'),
            default => null,
        };

        $this->SetValue('LastEvaluation', time());
        $this->SetValue('ActiveRule', $result ?? '');
        $this->SendDebug('Evaluate', $result ?? 'no result', 0);

        return true;
    }

    public function GetActiveRule(): string
    {
        return $this->GetValue('ActiveRule');
    }

    public function SetMode(int $mode): bool
    {
        if ($mode < 0 || $mode > 2) {
            return false;
        }

        IPS_SetProperty($this->InstanceID, 'Mode', $mode);
        IPS_ApplyChanges($this->InstanceID);

        return true;
    }

    public function ValidateFormula(string $formula): string
    {
        $this->ensureProLoader();
        if (!ProLoader::has('formula')) {
            return 'Formula mode requires bitCONTROL Plus';
        }

        $triggers = $this->getAllTriggers();
        $mode = $this->ReadPropertyInteger('Mode');
        $outputs = $this->getAllOutputs($mode);
        $allAliases = $this->collectAllAliases($triggers, $outputs);
        $provider = ProLoader::get('formula');

        if ($formula === '') {
            $formulaOutputs = json_decode($this->ReadPropertyString('FormulaOutputs'), true) ?: [];
            foreach ($formulaOutputs as $output) {
                $f = $output['formula'] ?? '';
                if ($f === '') {
                    continue;
                }
                $error = $provider->validate($f, $allAliases);
                if ($error !== '') {
                    return $error;
                }
            }
            return '';
        }

        return $provider->validate($formula, $allAliases);
    }

    public function ValidateScript(string $script): string
    {
        $this->ensureProLoader();
        if (!ProLoader::has('expert')) {
            return 'Expert mode requires bitCONTROL Pro';
        }

        if ($script === '') {
            $script = $this->ReadPropertyString('ExpertScript');
        }

        $outputs = $this->getAllOutputs(2);
        $outputAliases = array_filter(array_map(
            static fn(array $o) => $o['alias'] ?? '',
            $outputs
        ), static fn(string $a) => $a !== '');

        return ProLoader::get('expert')->validate($script, $outputAliases);
    }

    public function GetConfigurationForm(): string
    {
        $this->ensureProLoader();
        $mode = $this->ReadPropertyInteger('Mode');
        $triggers = $this->getAllTriggers();
        $rules = json_decode($this->ReadPropertyString('Rules'), true) ?: [];
        $formulaOutputs = json_decode($this->ReadPropertyString('FormulaOutputs'), true) ?: [];
        $expertOutputs = json_decode($this->ReadPropertyString('ExpertOutputs'), true) ?: [];

        $eventTriggers = array_values(array_filter($triggers, fn($t) => ($t['type'] ?? 'event') === 'event'));

        $triggerManager = new TriggerManager($this->InstanceID);
        $deactivatedByLimit = $triggerManager->getDeactivatedByLimit();

        $formulaEvaluation = $this->ReadPropertyInteger('FormulaEvaluation');
        $ruleEvaluation    = $this->ReadPropertyInteger('RuleEvaluation');
        FormBuilder::setTranslator(fn(string $s) => $this->Translate($s));
        $form = FormBuilder::build($mode, $triggers, $eventTriggers, $rules, $formulaOutputs, $expertOutputs, $formulaEvaluation, $ruleEvaluation, $deactivatedByLimit);

        return json_encode($form);
    }

    private function evaluateRules(): ?string
    {
        $rules          = json_decode($this->ReadPropertyString('Rules'), true) ?: [];
        $evaluationMode = $this->ReadPropertyInteger('RuleEvaluation');
        $hasTiming      = ProLoader::has('timing');
        $skipHeatup     = $hasTiming && $this->ReadPropertyBoolean('RuleSkipHeatup');
        $skipCooldown   = $hasTiming && $this->ReadPropertyBoolean('RuleSkipCooldown');
        $skipInterval   = $hasTiming && $this->ReadPropertyBoolean('RuleSkipInterval');

        $evaluator = new RuleEvaluator(
            $this->InstanceID,
            fn(string $key): int => $this->readRuleState($key),
            function (string $key, int $value): void {
                $this->writeRuleState($key, $value);
            }
        );

        return $evaluator->evaluate($rules, $evaluationMode, $skipHeatup, $skipCooldown, $skipInterval, $hasTiming, ProLoader::has('limiter'));
    }

    private function evaluateFormulas(array $aliasMap): ?string
    {
        $formulaOutputs = json_decode($this->ReadPropertyString('FormulaOutputs'), true) ?: [];
        $evaluationMode = $this->ReadPropertyInteger('FormulaEvaluation');
        $hasTiming      = ProLoader::has('timing');
        $skipHeatup     = $hasTiming && $this->ReadPropertyBoolean('FormulaSkipHeatup');
        $skipCooldown   = $hasTiming && $this->ReadPropertyBoolean('FormulaSkipCooldown');
        $skipInterval   = $hasTiming && $this->ReadPropertyBoolean('FormulaSkipInterval');
        $isFirstMatch   = $evaluationMode === 0;

        $timing = new TimingEvaluator(
            fn(string $key): int => $this->readFormulaState($key),
            function (string $key, int $value): void {
                $this->writeFormulaState($key, $value);
            }
        );

        $results = [];

        // Pre-populate all output aliases with their current variable values so
        // formulas can reference other outputs even when those outputs are skipped
        // this cycle due to heatup/cooldown/interval.
        $computedOutputs = [];
        foreach ($formulaOutputs as $output) {
            $alias      = $output['alias'] ?? '';
            $variableID = $output['variableID'] ?? 0;
            if ($alias !== '' && $variableID > 0 && IPS_VariableExists($variableID)) {
                $computedOutputs[$alias] = GetValue($variableID);
            }
        }

        foreach ($formulaOutputs as $output) {
            $formula    = $output['formula'] ?? '';
            $variableID = $output['variableID'] ?? 0;
            $alias      = $output['alias'] ?? '';

            if (empty($output['active']) || $formula === '' || $variableID === 0) {
                continue;
            }

            $stateKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $alias ?: (string)$variableID);

            $conditions    = $output['conditions'] ?? '[]';
            $conditionsMet = ($conditions === '' || $conditions === '[]') ? true : (bool)IPS_IsConditionPassing($conditions);
            $hasTiming     = ProLoader::has('timing');
            $delaySeconds  = $hasTiming ? (int)($output['delaySeconds'] ?? 0) * (int)($output['delayUnit'] ?? 1) : 0;
            $cooldownSeconds = $hasTiming ? (int)($output['cooldownSeconds'] ?? 0) * (int)($output['cooldownUnit'] ?? 1) : 0;
            $heatupReset   = (bool)($output['heatupResetOnInterruption'] ?? true);
            $cooldownReset = (bool)($output['cooldownResetOnReactivation'] ?? true);

            if ($conditionsMet) {
                $heatupStatus = $timing->checkHeatup($stateKey, $delaySeconds);
                if ($heatupStatus !== 'passed') {
                    if ($isFirstMatch && !$skipHeatup) {
                        break;
                    }
                    continue;
                }

                $timing->markActive($stateKey, $cooldownReset);
            } else {
                $timing->cancelHeatup($stateKey, $heatupReset);
                $cooldownStatus = $timing->checkCooldown($stateKey, $cooldownSeconds);

                if ($cooldownStatus === 'started' || $cooldownStatus === 'active') {
                    if ($skipCooldown) {
                        continue;
                    }
                    if ($isFirstMatch) {
                        break;
                    }
                    continue;
                }

                if ($cooldownStatus === 'expired') {
                    $timing->markInactive($stateKey);
                    $fallbackFormula = (ProLoader::has('limiter') && !empty($output['fallbackFormulaEnabled'])) ? ($output['fallbackFormula'] ?? '') : '';
                    if ($fallbackFormula !== '' && $variableID > 0) {
                        $evalMap = array_merge($aliasMap, $computedOutputs);
                        if ($alias !== '' && IPS_VariableExists($variableID)) {
                            $evalMap[$alias] = GetValue($variableID);
                        }
                        try {
                            $fallbackResult = ProLoader::get('formula')->evaluate($fallbackFormula, $evalMap);
                            RequestAction($variableID, $fallbackResult);
                        } catch (\Throwable $e) {
                            IPS_LogMessage('bitCONTROL', sprintf(
                                'Instance %d: Fallback formula failed for "%s": %s',
                                $this->InstanceID,
                                $alias ?: (string)$variableID,
                                $e->getMessage()
                            ));
                        }
                    }
                }
                continue;
            }

            $intervalSeconds = $hasTiming ? (int)($output['intervalSeconds'] ?? 0) * (int)($output['intervalUnit'] ?? 1) : 0;
            if (!$timing->checkInterval($stateKey, $intervalSeconds)) {
                if ($isFirstMatch && !$skipInterval) {
                    break;
                }
                continue;
            }
            $timing->markLastRun($stateKey);

            $evalMap = array_merge($aliasMap, $computedOutputs);
            if ($alias !== '' && IPS_VariableExists($variableID)) {
                $evalMap[$alias] = GetValue($variableID);
            }

            $result = ProLoader::get('formula')->evaluate($formula, $evalMap);
            RequestAction($variableID, $result);

            if ($alias !== '') {
                $computedOutputs[$alias] = $result;
            }

            $label = $alias !== '' ? $alias : (string)$variableID;
            $results[] = $label . '=' . (is_scalar($result) ? (string)$result : json_encode($result));

            if ($isFirstMatch) {
                break;
            }
        }

        return !empty($results) ? implode(', ', $results) : null;
    }

    private function evaluateExpert(array $aliasMap): ?string
    {
        $interval = $this->ReadPropertyInteger('ExpertInterval') * $this->ReadPropertyInteger('ExpertIntervalUnit');
        if ($interval > 0) {
            $lastRun = (int)$this->ReadAttributeString('ExpertLastRun');
            if ($lastRun > 0 && (time() - $lastRun) < $interval) {
                return null;
            }
            $this->WriteAttributeString('ExpertLastRun', (string)time());
        }

        $script = $this->ReadPropertyString('ExpertScript');
        $expertOutputs = json_decode($this->ReadPropertyString('ExpertOutputs'), true) ?: [];

        if ($script === '') {
            return null;
        }

        $outputAliases = [];
        $aliasToVariable = [];
        foreach ($expertOutputs as $output) {
            $alias = $output['alias'] ?? '';
            $variableID = $output['variableID'] ?? 0;
            if ($alias !== '' && $variableID > 0) {
                $outputAliases[] = $alias;
                $aliasToVariable[$alias] = $variableID;
            }
        }

        try {
            $results = ProLoader::get('expert')->execute($script, $aliasMap, $outputAliases);

            foreach ($results as $alias => $value) {
                if ($value !== null && isset($aliasToVariable[$alias])) {
                    RequestAction($aliasToVariable[$alias], $value);
                }
            }

            return !empty($results) ? implode(', ', array_map(
                static fn(string $k, mixed $v) => $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v)),
                array_keys($results),
                array_values($results)
            )) : 'executed';
        } catch (\Throwable $e) {
            $this->SendDebug('ExpertEvaluation', $e->getMessage(), 0);
            $this->SetStatus(203);
            return 'Error: ' . $e->getMessage();
        }
    }

    private function featureUnavailable(string $mode, string $tier): ?string
    {
        $this->SendDebug('Evaluate', sprintf('%s mode requires bitCONTROL %s', $mode, $tier), 0);
        return sprintf('%s mode unavailable', $mode);
    }

    private function ensureProLoader(): void
    {
        $this->ensureProLoader();
    }

    public function UIGetTriggerPopupForm(mixed $row): array
    {
        $row         = json_decode(json_encode($row), true) ?? [];
        $type        = $row['type'] ?? 'event';
        $dayPattern  = (int)($row['dayPattern'] ?? 0);
        $timePattern = (int)($row['timePattern'] ?? 0);
        $unlimited   = (bool)($row['unlimited'] ?? true);
        $isEvent     = $type === 'event';

        $hasInterval   = in_array($dayPattern, [0, 1, 2], true);
        $hasDateBounds = in_array($dayPattern, [0, 1, 2, 3], true);
        $suffixes      = [0 => ' Tag(e)', 1 => ' Woche(n)', 2 => ' Monat(e)'];
        $tSuffixes     = [1 => ' Sek.', 2 => ' Min.', 3 => ' Std.'];

        $form = [
            ['type' => 'CheckBox', 'name' => 'active', 'caption' => 'Active'],
            ['type' => 'Select', 'name' => 'type', 'caption' => 'Type',
                'options' => [['caption' => 'Event', 'value' => 'event'], ['caption' => 'Cyclic', 'value' => 'cyclic']],
                'onChange' => 'BIT_UIToggleTriggerType($id, $type);'],

            ['type' => 'SelectVariable', 'name' => 'variableID', 'caption' => 'Variable'],
            ['type' => 'RowLayout', 'name' => 'triggerRow', 'items' => [
                ['type' => 'Select', 'name' => 'eventType', 'caption' => 'Trigger',
                    'options' => [
                        ['caption' => 'On Change',      'value' => 1], ['caption' => 'On Update',     'value' => 0],
                        ['caption' => 'Limit Exceed',   'value' => 2], ['caption' => 'Limit Drop',    'value' => 3],
                        ['caption' => 'Specific Value', 'value' => 4],
                    ],
                    'onChange' => 'BIT_UIToggleThreshold($id, $eventType);'],
                ['type' => 'ValidationTextBox', 'name' => 'threshold', 'caption' => 'Threshold',
                    'visible' => $isEvent && in_array((int)($row['eventType'] ?? 1), [2, 3, 4], true)],
                ['type' => 'Select', 'name' => 'triggerRepeat', 'caption' => 'Subsequent Execution',
                    'options' => [
                        ['caption' => 'Once on first condition match', 'value' => 0],
                        ['caption' => 'On each update while condition met', 'value' => 1],
                    ],
                    'visible' => $isEvent && in_array((int)($row['eventType'] ?? 1), [2, 3, 4], true)],
                ['type' => 'NumberSpinner', 'name' => 'triggerLimit', 'caption' => 'Execution Limit',
                    'minimum' => 0, 'suffix' => ' (0 = unlimited)',
                    'visible' => $isEvent && in_array((int)($row['eventType'] ?? 1), [2, 3, 4], true)],
            ]],
            ['type' => 'ExpansionPanel', 'name' => 'triggerConditionsPanel', 'caption' => 'Event Conditions',
                'expanded' => false, 'visible' => $isEvent, 'items' => [
                ['type' => 'SelectCondition', 'name' => 'triggerConditions', 'multi' => true, 'caption' => ' '],
            ]],
            ['type' => 'ValidationTextBox', 'name' => 'alias', 'caption' => 'Alias',
                'validate'  => '^\$[a-zA-Z_][a-zA-Z0-9_]*$',
                'onChange'  => 'BIT_UIValidateAlias($id, $alias);'],
            ['type' => 'Label', 'name' => 'aliasError', 'caption' => '', 'foreground' => '0xFF0000'],

            ['type' => 'RowLayout', 'name' => 'dayPatternRow', 'items' => [
                ['type' => 'Select', 'name' => 'dayPattern', 'caption' => 'Day Pattern',
                    'options' => [
                        ['caption' => 'Day Interval',      'value' => 0], ['caption' => 'Week Interval',   'value' => 1],
                        ['caption' => 'Month Interval',    'value' => 2], ['caption' => 'One Day per Year', 'value' => 3],
                        ['caption' => 'Specific Date',     'value' => 4],
                    ],
                    'onChange' => 'BIT_UIToggleCyclicFields($id, $dayPattern);'],
                ['type' => 'NumberSpinner', 'name' => 'dayInterval', 'caption' => 'Every', 'minimum' => 1,
                    'suffix' => $suffixes[$dayPattern] ?? ' Tag(e)'],
            ]],

            ['type' => 'RowLayout', 'name' => 'weekdayRow', 'items' => [
                ['type' => 'CheckBox', 'name' => 'wdMon', 'caption' => 'Mo'],
                ['type' => 'CheckBox', 'name' => 'wdTue', 'caption' => 'Tu'],
                ['type' => 'CheckBox', 'name' => 'wdWed', 'caption' => 'We'],
                ['type' => 'CheckBox', 'name' => 'wdThu', 'caption' => 'Th'],
                ['type' => 'CheckBox', 'name' => 'wdFri', 'caption' => 'Fr'],
                ['type' => 'CheckBox', 'name' => 'wdSat', 'caption' => 'Sa'],
                ['type' => 'CheckBox', 'name' => 'wdSun', 'caption' => 'Su'],
            ]],

            ['type' => 'RowLayout', 'name' => 'monthRow', 'items' => [
                ['type' => 'Select', 'name' => 'monthOrdinal', 'caption' => 'On',
                    'options' => [
                        ['caption' => '1.', 'value' => 1], ['caption' => '2.', 'value' => 2],
                        ['caption' => '3.', 'value' => 3], ['caption' => '4.', 'value' => 4],
                        ['caption' => 'Last.', 'value' => 5],
                    ]],
                ['type' => 'Select', 'name' => 'monthDayType', 'caption' => 'Day Type',
                    'options' => [
                        ['caption' => 'Day', 'value' => 0],        ['caption' => 'Weekday',   'value' => 1],
                        ['caption' => 'Monday', 'value' => 2],     ['caption' => 'Tuesday',   'value' => 3],
                        ['caption' => 'Wednesday', 'value' => 4],  ['caption' => 'Thursday',  'value' => 5],
                        ['caption' => 'Friday', 'value' => 6],     ['caption' => 'Saturday',  'value' => 7],
                        ['caption' => 'Sunday', 'value' => 8],
                    ]],
            ]],

            ['type' => 'RowLayout', 'name' => 'yearRow', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'yearDay', 'caption' => 'Day', 'minimum' => 1, 'maximum' => 31],
                ['type' => 'Select', 'name' => 'yearMonth', 'caption' => 'Month',
                    'options' => [
                        ['caption' => 'January', 'value' => 1],    ['caption' => 'February',  'value' => 2],
                        ['caption' => 'March',   'value' => 3],    ['caption' => 'April',     'value' => 4],
                        ['caption' => 'May',     'value' => 5],    ['caption' => 'June',      'value' => 6],
                        ['caption' => 'July',    'value' => 7],    ['caption' => 'August',    'value' => 8],
                        ['caption' => 'September', 'value' => 9],  ['caption' => 'October',   'value' => 10],
                        ['caption' => 'November',  'value' => 11], ['caption' => 'December',  'value' => 12],
                    ]],
            ]],

            ['type' => 'SelectDate', 'name' => 'specificDate', 'caption' => 'On'],
            ['type' => 'RowLayout', 'name' => 'dateRow', 'items' => [
                ['type' => 'SelectDate', 'name' => 'dateFrom',  'caption' => 'From'],
                ['type' => 'SelectDate', 'name' => 'dateTo',    'caption' => 'To'],
                ['type' => 'CheckBox',   'name' => 'unlimited', 'caption' => 'Unlimited',
                    'onChange' => 'BIT_UIToggleUnlimited($id, $unlimited);'],
            ]],

            ['type' => 'RowLayout', 'name' => 'timePatternRow', 'items' => [
                ['type' => 'Select', 'name' => 'timePattern', 'caption' => 'Time Pattern',
                    'options' => [
                        ['caption' => 'At Specific Time', 'value' => 0], ['caption' => 'Every Second', 'value' => 1],
                        ['caption' => 'Every Minute',     'value' => 2], ['caption' => 'Every Hour',   'value' => 3],
                    ],
                    'onChange' => 'BIT_UIToggleTimePattern($id, $timePattern);'],
                ['type' => 'SelectTime',    'name' => 'timeValue',    'caption' => 'At'],
                ['type' => 'NumberSpinner', 'name' => 'timeInterval', 'caption' => 'Every', 'minimum' => 1,
                    'suffix' => $tSuffixes[$timePattern] ?? ''],
            ]],
            ['type' => 'RowLayout', 'name' => 'timeRow', 'items' => [
                ['type' => 'SelectTime', 'name' => 'timeFrom', 'caption' => 'From'],
                ['type' => 'SelectTime', 'name' => 'timeTo',   'caption' => 'To'],
            ]],
        ];

        foreach ($form as &$el) {
            $name = $el['name'] ?? '';
            $el['visible'] = match ($name) {
                'type' => true,
                'variableID', 'triggerRow', 'eventType' => $isEvent,
                'threshold' => $isEvent && in_array((int)($row['eventType'] ?? 1), [2, 3, 4], true),
                'triggerConditionsPanel' => $isEvent,
                'alias' => $isEvent,
                'aliasError' => false,
                'dayPattern', 'timePattern' => !$isEvent,
                'dayPatternRow', 'dayInterval' => !$isEvent && $hasInterval,
                'weekdayRow', 'wdMon', 'wdTue', 'wdWed', 'wdThu', 'wdFri', 'wdSat', 'wdSun' => !$isEvent && $dayPattern === 1,
                'monthRow', 'monthOrdinal', 'monthDayType' => !$isEvent && $dayPattern === 2,
                'yearRow', 'yearDay', 'yearMonth' => !$isEvent && $dayPattern === 3,
                'specificDate' => !$isEvent && $dayPattern === 4,
                'dateRow', 'dateFrom', 'unlimited' => !$isEvent && $hasDateBounds,
                'dateTo' => !$isEvent && $hasDateBounds && !$unlimited,
                'timePatternRow', 'timeValue' => !$isEvent && $timePattern === 0,
                'timeRow', 'timeInterval', 'timeFrom', 'timeTo' => !$isEvent && $timePattern > 0,
                default => true,
            };
        }
        unset($el);

        usort($form, fn($a, $b) => ($b['visible'] ? 1 : 0) - ($a['visible'] ? 1 : 0));

        return $form;
    }

    public function UIToggleRuleSkip(int $mode): void
    {
        $visible = $mode === 0;
        $this->UpdateFormField('RuleSkipHeatup', 'visible', $visible);
        $this->UpdateFormField('RuleSkipCooldown', 'visible', $visible);
        $this->UpdateFormField('RuleSkipInterval', 'visible', $visible);
    }

    public function UIGetRulePopupForm(mixed $row): array
    {
        $this->ensureProLoader();
        $row = json_decode(json_encode($row), true) ?? [];
        FormBuilder::setTranslator(fn(string $s) => $this->Translate($s));
        return FormBuilder::buildRulePopupForm($row);
    }

    public function UIGetFormulaPopupForm(mixed $row): array
    {
        $this->ensureProLoader();
        $row = json_decode(json_encode($row), true) ?? [];
        $triggers = $this->getAllTriggers();
        $triggerAliases = array_filter(array_column(
            array_filter($triggers, fn($t) => ($t['type'] ?? 'event') === 'event'),
            'alias'
        ));
        FormBuilder::setTranslator(fn(string $s) => $this->Translate($s));
        return FormBuilder::buildFormulaPopupForm($row, $triggerAliases);
    }

    public function UIToggleFallbackActions(bool $enabled): void
    {
        $this->UpdateFormField('fallbackActions', 'visible', $enabled);
    }

    public function UIToggleFallbackFormula(bool $enabled): void
    {
        $this->UpdateFormField('fallbackFormula', 'visible', $enabled);
    }

    public function UIToggleFormulaSkip(int $mode): void
    {
        $visible = $mode === 0;
        $this->UpdateFormField('FormulaSkipHeatup', 'visible', $visible);
        $this->UpdateFormField('FormulaSkipCooldown', 'visible', $visible);
        $this->UpdateFormField('FormulaSkipInterval', 'visible', $visible);
    }

    public function UIToggleTriggerType(string $type): void
    {
        $isEvent = $type === 'event';

        $this->UpdateFormField('variableID', 'visible', $isEvent);
        $this->UpdateFormField('triggerRow', 'visible', $isEvent);
        $this->UpdateFormField('eventType', 'visible', $isEvent);
        $this->UpdateFormField('threshold', 'visible', false);
        $this->UpdateFormField('triggerConditionsPanel', 'visible', $isEvent);
        $this->UpdateFormField('alias', 'visible', $isEvent);

        $this->UpdateFormField('dayPatternRow', 'visible', !$isEvent);
        $this->UpdateFormField('dayPattern', 'visible', !$isEvent);
        $this->UpdateFormField('timePatternRow', 'visible', !$isEvent);
        $this->UpdateFormField('timePattern', 'visible', !$isEvent);

        foreach ([
            'dayPatternRow', 'dayInterval', 'weekdayRow', 'wdMon', 'wdTue', 'wdWed', 'wdThu', 'wdFri', 'wdSat', 'wdSun',
            'monthRow', 'monthOrdinal', 'monthDayType', 'yearRow', 'yearDay', 'yearMonth',
            'dateRow', 'dateFrom', 'unlimited', 'dateTo', 'specificDate',
            'timePatternRow', 'timeRow', 'timeValue', 'timeInterval', 'timeFrom', 'timeTo',
        ] as $f) {
            $this->UpdateFormField($f, 'visible', false);
        }

        if (!$isEvent) {
            $this->UIToggleCyclicFields(0);
            $this->UIToggleTimePattern(0);
        }
    }

    public function UIRefreshTriggers(mixed $currentTriggers): void
    {
        $triggers = json_decode(json_encode($currentTriggers), true) ?: [];
        $values      = [];
        $seenAliases = [];
        foreach ($triggers as $trigger) {
            $color  = FormBuilder::triggerRowColor($trigger, $seenAliases);
            $type   = $trigger['type'] ?? 'event';
            $alias  = $trigger['alias'] ?? '';

            if ($type === 'event') {
                if ($alias === '') {
                    $status = $this->Translate('Alias must not be empty');
                } elseif (in_array($alias, $seenAliases, true)) {
                    $status = $this->Translate('Alias must be unique');
                } elseif (!preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                    $status = $this->Translate('Invalid alias detected');
                } elseif (($trigger['variableID'] ?? 0) === 0) {
                    $status = $this->Translate('Variable does not exist');
                } else {
                    $status = 'OK';
                    $cond = $trigger['triggerConditions'] ?? '[]';
                    $condArr = is_string($cond) ? (json_decode($cond, true) ?: []) : (is_array($cond) ? $cond : []);
                    $condCount = count($condArr);
                    if ($condCount > 0) {
                        $status .= ' [+' . $condCount . ']';
                    }
                }
                if ($alias !== '') {
                    $seenAliases[] = $alias;
                }
            } else {
                $status = FormBuilder::formatCyclicStatusTranslated($trigger, fn(string $s) => $this->Translate($s));
            }

            $values[] = array_merge($trigger, ['rowColor' => $color, 'triggerStatus' => $status]);
        }

        $this->UpdateFormField('Triggers', 'values', json_encode($values));
    }

    public function UIValidateFormulaField(string $formula): void
    {
        $this->ensureProLoader();
        $triggers = $this->getAllTriggers();
        $mode     = $this->ReadPropertyInteger('Mode');
        $outputs  = $this->getAllOutputs($mode);
        $allAliases = $this->collectAllAliases($triggers, $outputs);

        $error    = $formula === '' ? '' : (ProLoader::has('formula') ? ProLoader::get('formula')->validate($formula, $allAliases) : 'Formula mode requires bitCONTROL Plus');
        $hasError = $error !== '';

        $this->UpdateFormField('formulaError', 'caption', $hasError ? $error : '');
        $this->UpdateFormField('formulaError', 'visible', $hasError);
        $this->UpdateFormField('formula', 'caption', $hasError ? '⚠ Formula' : 'Formula');
    }

    public function UIValidateFallbackFormulaField(string $formula): void
    {
        $this->ensureProLoader();
        $triggers = $this->getAllTriggers();
        $mode     = $this->ReadPropertyInteger('Mode');
        $outputs  = $this->getAllOutputs($mode);
        $allAliases = $this->collectAllAliases($triggers, $outputs);

        $error    = $formula === '' ? '' : (ProLoader::has('formula') ? ProLoader::get('formula')->validate($formula, $allAliases) : 'Formula mode requires bitCONTROL Plus');
        $hasError = $error !== '';

        $this->UpdateFormField('fallbackFormulaError', 'caption', $hasError ? $error : '');
        $this->UpdateFormField('fallbackFormulaError', 'visible', $hasError);
        $this->UpdateFormField('fallbackFormula', 'caption', $hasError ? '⚠ Fallback' : ' ');
    }

    public function UIValidateAlias(string $alias): void
    {
        $existing = [];
        $triggers = json_decode($this->ReadPropertyString('Triggers'), true) ?: [];
        foreach ($triggers as $t) {
            $a = $t['alias'] ?? '';
            if ($a !== '') {
                $existing[] = $a;
            }
        }

        $error    = AliasValidator::validate($alias, $existing);
        $hasError = $error !== '';

        $this->UpdateFormField('aliasError', 'visible', $hasError);
        $this->UpdateFormField('aliasError', 'caption', $hasError ? $error : '');
    }

    public function UIToggleThreshold(int $eventType): void
    {
        $this->UpdateFormField('triggerRow', 'visible', true);
        $this->UpdateFormField('threshold', 'visible', in_array($eventType, [2, 3, 4], true));
        $this->UpdateFormField('triggerRepeat', 'visible', in_array($eventType, [2, 3, 4], true));
        $this->UpdateFormField('triggerLimit', 'visible', in_array($eventType, [2, 3, 4], true));
    }

    public function UIToggleCyclicFields(int $dayPattern): void
    {
        $hasInterval = in_array($dayPattern, [0, 1, 2], true);
        $this->UpdateFormField('dayPatternRow', 'visible', true);
        $this->UpdateFormField('dayInterval', 'visible', $hasInterval);
        $suffixes = [0 => ' Tag(e)', 1 => ' Woche(n)', 2 => ' Monat(e)'];
        $this->UpdateFormField('dayInterval', 'suffix', $suffixes[$dayPattern] ?? '');

        $this->UpdateFormField('weekdayRow', 'visible', $dayPattern === 1);
        foreach (['wdMon', 'wdTue', 'wdWed', 'wdThu', 'wdFri', 'wdSat', 'wdSun'] as $f) {
            $this->UpdateFormField($f, 'visible', $dayPattern === 1);
        }

        $this->UpdateFormField('monthRow', 'visible', $dayPattern === 2);
        $this->UpdateFormField('monthOrdinal', 'visible', $dayPattern === 2);
        $this->UpdateFormField('monthDayType', 'visible', $dayPattern === 2);

        $this->UpdateFormField('yearRow', 'visible', $dayPattern === 3);
        $this->UpdateFormField('yearDay', 'visible', $dayPattern === 3);
        $this->UpdateFormField('yearMonth', 'visible', $dayPattern === 3);

        $hasDateBounds = in_array($dayPattern, [0, 1, 2, 3], true);
        $this->UpdateFormField('dateRow', 'visible', $hasDateBounds);
        $this->UpdateFormField('dateFrom', 'visible', $hasDateBounds);
        $this->UpdateFormField('unlimited', 'visible', $hasDateBounds);
        $this->UpdateFormField('dateTo', 'visible', false);

        $this->UpdateFormField('specificDate', 'visible', $dayPattern === 4);
    }

    public function UIToggleUnlimited(bool $unlimited): void
    {
        $this->UpdateFormField('dateTo', 'visible', !$unlimited);
    }

    public function UIToggleTimePattern(int $timePattern): void
    {
        $isInterval = $timePattern > 0;
        $this->UpdateFormField('timePatternRow', 'visible', true);
        $this->UpdateFormField('timeValue', 'visible', !$isInterval);
        $this->UpdateFormField('timeRow', 'visible', $isInterval);
        $this->UpdateFormField('timeInterval', 'visible', $isInterval);
        $this->UpdateFormField('timeFrom', 'visible', $isInterval);
        $this->UpdateFormField('timeTo', 'visible', $isInterval);
        $suffixes = [1 => ' Sek.', 2 => ' Min.', 3 => ' Std.'];
        if (isset($suffixes[$timePattern])) {
            $this->UpdateFormField('timeInterval', 'suffix', $suffixes[$timePattern]);
        }
    }

    private function getAllTriggers(): array
    {
        return json_decode($this->ReadPropertyString('Triggers'), true) ?: [];
    }

    private function getAllOutputs(int $mode): array
    {
        return match ($mode) {
            1 => json_decode($this->ReadPropertyString('FormulaOutputs'), true) ?: [],
            2 => json_decode($this->ReadPropertyString('ExpertOutputs'), true) ?: [],
            default => [],
        };
    }

    private function collectAllAliases(array $triggers, array $outputs): array
    {
        $aliases = [];

        foreach ($triggers as $trigger) {
            $alias = $trigger['alias'] ?? '';
            if ($alias !== '') {
                $aliases[] = $alias;
            }
        }

        foreach ($outputs as $output) {
            $alias = $output['alias'] ?? '';
            if ($alias !== '') {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    private function pruneOrphanedState(int $mode): void
    {
        if ($mode === 0) {
            $rules = json_decode($this->ReadPropertyString('Rules'), true) ?: [];
            $validKeys = array_map(fn($r, $i) => RuleEvaluator::ruleKey($r, $i), $rules, array_keys($rules));
            $state = json_decode($this->ReadAttributeString('RuleState'), true) ?: [];
            $pruned = $this->pruneStateKeys($state, $validKeys);
            $this->WriteAttributeString('RuleState', json_encode($pruned));
        }

        if ($mode === 1) {
            $formulaOutputs = json_decode($this->ReadPropertyString('FormulaOutputs'), true) ?: [];
            $validKeys = array_map(function ($o) {
                $alias = $o['alias'] ?? '';
                $variableID = $o['variableID'] ?? 0;
                return preg_replace('/[^a-zA-Z0-9_]/', '_', $alias ?: (string)$variableID);
            }, $formulaOutputs);
            $state = json_decode($this->ReadAttributeString('FormulaState'), true) ?: [];
            $pruned = $this->pruneStateKeys($state, $validKeys);
            $this->WriteAttributeString('FormulaState', json_encode($pruned));
        }
    }

    private function pruneStateKeys(array $state, array $validKeys): array
    {
        $prefixes = ['HeatupStart_', 'WasActive_', 'CooldownStart_', 'LastRun_', 'DelayStart_'];
        $pruned = [];

        foreach ($state as $key => $value) {
            $isOrphan = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $stateKey = substr($key, strlen($prefix));
                    if (!in_array($stateKey, $validKeys, true)) {
                        $isOrphan = true;
                    }
                    break;
                }
            }
            if (!$isOrphan) {
                $pruned[$key] = $value;
            }
        }

        return $pruned;
    }

    private function readRuleState(string $key): int
    {
        $state = json_decode($this->ReadAttributeString('RuleState'), true) ?: [];
        return $state[$key] ?? 0;
    }

    private function writeRuleState(string $key, int $value): void
    {
        $state = json_decode($this->ReadAttributeString('RuleState'), true) ?: [];
        $state[$key] = $value;
        $this->WriteAttributeString('RuleState', json_encode($state));
    }

    private function readFormulaState(string $key): int
    {
        $state = json_decode($this->ReadAttributeString('FormulaState'), true) ?: [];
        return $state[$key] ?? 0;
    }

    private function writeFormulaState(string $key, int $value): void
    {
        $state = json_decode($this->ReadAttributeString('FormulaState'), true) ?: [];
        $state[$key] = $value;
        $this->WriteAttributeString('FormulaState', json_encode($state));
    }
}
