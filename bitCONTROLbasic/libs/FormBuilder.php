<?php

declare(strict_types=1);

class FormBuilder
{
    private static $translate = null;

    public static function setTranslator(callable $fn): void
    {
        self::$translate = $fn;
    }

    private static function t(string $s): string
    {
        return self::$translate ? (self::$translate)($s) : $s;
    }

    public static function triggerRowColor(array $trigger, array $seenAliases): string
    {
        $type  = $trigger['type'] ?? 'event';
        $alias = $trigger['alias'] ?? '';

        if ($type === 'event') {
            if ($alias === '') {
                return '#FFCCCC';
            }
            if (in_array($alias, $seenAliases, true)) {
                return '#FFCCCC';
            }
            if (!preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                return '#FFCCCC';
            }
            if (($trigger['variableID'] ?? 0) === 0) {
                return '#FFEECC';
            }
        }

        return '#FFFFFF';
    }

    public static function formatCyclicStatusTranslated(array $t, callable $translator): string
    {
        return self::formatCyclicStatusInternal($t, $translator);
    }

    private static function formatCyclicStatus(array $t): string
    {
        return self::formatCyclicStatusInternal($t, [self::class, 't']);
    }

    private static function formatCyclicStatusInternal(array $t, callable $tr): string
    {
        $dayPattern  = (int)($t['dayPattern'] ?? 0);
        $dayInterval = (int)($t['dayInterval'] ?? 1);
        $timePattern = (int)($t['timePattern'] ?? 0);
        $tv = $t['timeValue'] ?? 0;
        if (is_string($tv) && str_starts_with(trim($tv), '{')) {
            $tv = json_decode($tv, true) ?? 0;
        }
        $timeValue = is_array($tv)
            ? ((int)($tv['hour'] ?? 0)) * 3600 + ((int)($tv['minute'] ?? 0)) * 60
            : (int)$tv;
        $timeInterval = (int)($t['timeInterval'] ?? 1);

        switch ($dayPattern) {
            case 0:
                $day = $tr('Every') . ' ' . $dayInterval . ' ' . $tr('Day') . '(e)';
                break;
            case 1:
                $days = [];
                $map = ['wdMon' => 'Mo', 'wdTue' => 'Tu', 'wdWed' => 'We', 'wdThu' => 'Th', 'wdFri' => 'Fr', 'wdSat' => 'Sa', 'wdSun' => 'Su'];
                foreach ($map as $key => $label) {
                    if (!empty($t[$key])) {
                        $days[] = $tr($label);
                    }
                }
                $day = $tr('Every') . ' ' . $dayInterval . ' ' . $tr('Week Interval') . (!empty($days) ? ' (' . implode(',', $days) . ')' : '');
                break;
            case 2:
                $ordLabels = [1 => '1.', 2 => '2.', 3 => '3.', 4 => '4.', 5 => $tr('Last.')];
                $dtLabels  = [0 => $tr('Day'), 1 => $tr('Weekday'), 2 => $tr('Monday'), 3 => $tr('Tuesday'), 4 => $tr('Wednesday'), 5 => $tr('Thursday'), 6 => $tr('Friday'), 7 => $tr('Saturday'), 8 => $tr('Sunday')];
                $ord = $ordLabels[(int)($t['monthOrdinal'] ?? 1)] ?? '1.';
                $dt  = $dtLabels[(int)($t['monthDayType'] ?? 0)] ?? $tr('Day');
                $day = $tr('Every') . ' ' . $dayInterval . ' ' . $tr('Month') . ', ' . $ord . ' ' . $dt;
                break;
            case 3:
                $months = [$tr('January'), $tr('February'), $tr('March'), $tr('April'), $tr('May'), $tr('June'),
                           $tr('July'), $tr('August'), $tr('September'), $tr('October'), $tr('November'), $tr('December')];
                $m   = $months[((int)($t['yearMonth'] ?? 1)) - 1] ?? $tr('January');
                $day = $tr('Day') . ' ' . ($t['yearDay'] ?? 1) . '. ' . $m;
                break;
            case 4:
                $day = $tr('On') . ' ' . ($t['specificDate'] ?? '?');
                break;
            default:
                $day = '?';
        }

        $parseTime = static function (mixed $v): string {
            if (is_string($v) && str_starts_with(trim($v), '{')) {
                $v = json_decode($v, true) ?? [];
            }
            if (is_array($v)) {
                return sprintf('%02d:%02d', (int)($v['hour'] ?? 0), (int)($v['minute'] ?? 0));
            }
            $sec = (int)$v;
            return sprintf('%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
        };

        switch ($timePattern) {
            case 0:
                $h = intdiv($timeValue, 3600);
                $m = intdiv($timeValue % 3600, 60);
                $time = $tr('At') . ' ' . sprintf('%02d:%02d', $h, $m);
                break;
            case 1:
            case 2:
            case 3:
                $labels = [1 => $tr('Sec.'), 2 => $tr('Min.'), 3 => $tr('Hr.')];
                $from = $parseTime($t['timeFrom'] ?? 0);
                $to   = $parseTime($t['timeTo'] ?? 0);
                $range = ($from !== $to) ? ', ' . $from . '–' . $to : '';
                $time = $tr('Every') . ' ' . $timeInterval . ' ' . $labels[$timePattern] . $range;
                break;
            default:
                $time = '';
        }

        return $day . ', ' . $time;
    }

    public static function build(int $mode, array $allTriggers, array $eventTriggers, array $rules, array $formulaOutputs, array $expertOutputs, int $formulaEvaluation = 1, int $ruleEvaluation = 1, array $deactivatedByLimit = [], array $combinedOrder = [], int $combinedEvaluation = 1): array
    {
        $elements = [];

        $elements[] = [
            'type'    => 'CheckBox',
            'name'    => 'Active',
            'caption' => 'Active',
        ];

        $elements[] = self::buildTriggerPanel($allTriggers, $deactivatedByLimit);

        $modeOptions = [['caption' => 'Rule', 'value' => 0]];
        if (ProLoader::has('formula')) {
            $modeOptions[] = ['caption' => 'Formula', 'value' => 1];
        } else {
            $modeOptions[] = ['caption' => 'Formula (Plus)', 'value' => 1];
        }
        if (ProLoader::has('expert')) {
            $modeOptions[] = ['caption' => 'Expert', 'value' => 2];
        } else {
            $modeOptions[] = ['caption' => 'Expert (Pro)', 'value' => 2];
        }
        if (ProLoader::has('combined')) {
            $modeOptions[] = ['caption' => 'Combined', 'value' => 3];
        } else {
            $modeOptions[] = ['caption' => 'Combined (Pro)', 'value' => 3];
        }

        $elements[] = [
            'type'    => 'Select',
            'name'    => 'Mode',
            'caption' => 'Steering Mode',
            'options' => $modeOptions,
        ];

        switch ($mode) {
            case 0:
                $elements = array_merge($elements, self::buildRuleElements($rules, $ruleEvaluation));
                break;
            case 1:
                if (ProLoader::has('formula')) {
                    $elements = array_merge($elements, self::buildFormulaElements($eventTriggers, $formulaOutputs, $formulaEvaluation));
                } else {
                    $elements[] = ['type' => 'Label', 'caption' => self::t('Formula mode requires bitCONTROL Plus.'), 'bold' => true, 'color' => 0xCC8800];
                }
                break;
            case 2:
                if (ProLoader::has('expert')) {
                    $elements = array_merge($elements, self::buildExpertElements($eventTriggers));
                } else {
                    $elements[] = ['type' => 'Label', 'caption' => self::t('Expert mode requires bitCONTROL Pro.'), 'bold' => true, 'color' => 0xCC8800];
                }
                break;
            case 3:
                if (ProLoader::has('combined')) {
                    $elements = array_merge($elements, self::buildCombinedElements($rules, $formulaOutputs, $combinedOrder, $combinedEvaluation));
                } else {
                    $elements[] = ['type' => 'Label', 'caption' => self::t('Combined mode requires bitCONTROL Pro.'), 'bold' => true, 'color' => 0xCC8800];
                }
                break;
        }

        $form = [
            'elements' => $elements,
            'actions'  => self::buildActions(),
            'status'   => self::buildStatus(),
        ];

        // Extension hook for paid tier form elements
        $formExt = ProLoader::get('formbuilder');
        if ($formExt) {
            $form = $formExt->extend($form, [
                'mode' => $mode,
                'triggers' => $allTriggers,
                'rules' => $rules,
                'formulaOutputs' => $formulaOutputs,
                'expertOutputs' => $expertOutputs,
            ]);
        }

        // License status (read-only, managed by bitCONTROL License splitter)
        $form['actions'][] = ['type' => 'Label', 'caption' => '', 'separator' => true];
        $form['actions'][] = self::buildLicenseStatus();

        return $form;
    }

    private static function buildTriggerPanel(array $triggers, array $deactivatedByLimit = []): array
    {
        $values      = [];
        $seenAliases = [];
        foreach ($triggers as $trigger) {
            $color  = self::triggerRowColor($trigger, $seenAliases);
            $alias  = $trigger['alias'] ?? '';
            $type   = $trigger['type'] ?? 'event';

            if ($type === 'event') {
                if (($trigger['variableID'] ?? 0) === 0) {
                    $status = self::t('Variable does not exist');
                } elseif ($alias === '') {
                    $status = self::t('Alias must not be empty');
                } elseif (in_array($alias, $seenAliases, true)) {
                    $status = self::t('Alias must be unique');
                } elseif (!preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
                    $status = self::t('Invalid alias detected');
                } else {
                    $eventLabels = [0 => 'Update', 1 => 'Change', 2 => 'Limit >', 3 => 'Limit <', 4 => 'Wert ='];
                    $eventType   = (int)($trigger['eventType'] ?? 1);
                    $label       = $eventLabels[$eventType] ?? 'Change';
                    $threshold   = $trigger['threshold'] ?? '';
                    $status      = $threshold !== '' ? $label . ' ' . $threshold : $label;
                    $cond = $trigger['triggerConditions'] ?? '[]';
                    $condArr = is_string($cond) ? (json_decode($cond, true) ?: []) : (is_array($cond) ? $cond : []);
                    $condCount = count($condArr);
                    if ($condCount > 0) {
                        $status .= ' [+' . $condCount . ']';
                    }
                    $lookupKey = $alias !== '' ? $alias : '#' . ($trigger['variableID'] ?? 0);
                    if (isset($deactivatedByLimit[$lookupKey])) {
                        $status .= ' (' . self::t('limit reached') . ')';
                        $color = '#FFEECC';
                    }
                }
                if ($alias !== '') {
                    $seenAliases[] = $alias;
                }
            } else {
                $status = self::formatCyclicStatus($trigger);
            }

            $values[] = array_merge($trigger, ['rowColor' => $color, 'triggerStatus' => $status]);
        }

        $triggerManager = new TriggerManager(0);
        $maxTriggers = $triggerManager->getMaxTriggers();
        $triggerAtLimit = count($triggers) >= $maxTriggers;
        $maxDisplay = ProLoader::has('limiter') ? self::t('unlimited') : (string)$maxTriggers;
        $caption = self::t('Triggers') . ' (' . count($triggers) . ' / ' . $maxDisplay . ')';

        return [
            'type'     => 'ExpansionPanel',
            'caption'  => $caption,
            'expanded' => true,
            'items'    => [
                [
                    'type'        => 'List',
                    'name'        => 'Triggers',
                    'add'         => !$triggerAtLimit,
                    'delete'      => true,
                    'changeOrder' => true,
                    'rowCount'    => 5,
                    'form'        => ['return BIT_UIGetTriggerPopupForm($id, $Triggers);'],
                    'values'      => $values,
                    'columns'     => [
                        [
                            'caption' => 'Active',
                            'name'    => 'active',
                            'width'   => '50px',
                            'add'     => true,
                            'edit'    => ['type' => 'CheckBox'],
                        ],
                        [
                            'caption' => 'Type',
                            'name'    => 'type',
                            'width'   => '80px',
                            'add'     => 'event',
                            'edit'    => [
                                'type'     => 'Select',
                                'options'  => [
                                    ['caption' => 'Event', 'value' => 'event'],
                                    ['caption' => 'Cyclic', 'value' => 'cyclic'],
                                ],
                                'onChange' => 'BIT_UIToggleTriggerType($id, $type);',
                            ],
                        ],
                        [
                            'caption' => 'Variable',
                            'name'    => 'variableID',
                            'width'   => '200px',
                            'add'     => 0,
                            'edit'    => ['type' => 'SelectVariable'],
                        ],
                        [
                            'caption' => 'Trigger',
                            'name'    => 'eventType',
                            'width'   => '140px',
                            'add'     => 1,
                            'edit'    => [
                                'type'    => 'Select',
                                'options' => [
                                    ['caption' => 'On Change', 'value' => 1],
                                    ['caption' => 'On Update', 'value' => 0],
                                    ['caption' => 'Limit Exceed', 'value' => 2],
                                    ['caption' => 'Limit Drop', 'value' => 3],
                                    ['caption' => 'Specific Value', 'value' => 4],
                                ],
                            ],
                        ],
                        [
                            'caption' => '',
                            'name'    => 'threshold',
                            'width'   => '0px',
                            'add'     => '',
                            'visible' => false,
                            'edit'    => ['type' => 'ValidationTextBox'],
                        ],
                        [
                            'caption' => '',
                            'name'    => 'triggerRepeat',
                            'width'   => '0px',
                            'add'     => 0,
                            'visible' => false,
                            'edit'    => ['type' => 'Select', 'options' => [
                                ['caption' => 'Once on first condition match', 'value' => 0],
                                ['caption' => 'On each update while condition met', 'value' => 1],
                            ]],
                        ],
                        [
                            'caption' => '',
                            'name'    => 'triggerLimit',
                            'width'   => '0px',
                            'add'     => 0,
                            'visible' => false,
                            'edit'    => ['type' => 'NumberSpinner', 'minimum' => 0],
                        ],
                        [
                            'caption' => '',
                            'name'    => 'triggerConditions',
                            'width'   => '0px',
                            'add'     => '[]',
                            'visible' => false,
                            'edit'    => ['type' => 'SelectCondition', 'multi' => true],
                        ],
                        [
                            'caption' => 'Alias',
                            'name'    => 'alias',
                            'width'   => '100px',
                            'add'     => '',
                            'edit'    => ['type' => 'ValidationTextBox'],
                        ],
                        [
                            'caption' => 'Status',
                            'name'    => 'triggerStatus',
                            'width'   => 'auto',
                            'add'     => '',
                        ],
                        ['caption' => 'Day Pattern', 'name' => 'dayPattern', 'width' => '0px', 'add' => 0, 'visible' => false,
                            'edit' => ['type' => 'Select', 'options' => [
                                ['caption' => 'Day Interval', 'value' => 0], ['caption' => 'Week Interval', 'value' => 1],
                                ['caption' => 'Month Interval', 'value' => 2], ['caption' => 'One Day per Year', 'value' => 3],
                                ['caption' => 'Specific Date', 'value' => 4],
                            ], 'onChange' => 'BIT_UIToggleCyclicFields($id, $dayPattern);']],
                        ['caption' => 'Every', 'name' => 'dayInterval', 'width' => '0px', 'add' => 1, 'visible' => false,
                            'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'suffix' => ' Tag(e)']],
                        ['caption' => 'Mo', 'name' => 'wdMon', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Tu', 'name' => 'wdTue', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'We', 'name' => 'wdWed', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Th', 'name' => 'wdThu', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Fr', 'name' => 'wdFri', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Sa', 'name' => 'wdSat', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Su', 'name' => 'wdSun', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'On', 'name' => 'monthOrdinal', 'width' => '0px', 'add' => 1, 'visible' => false,
                            'edit' => ['type' => 'Select', 'options' => [
                                ['caption' => '1.', 'value' => 1], ['caption' => '2.', 'value' => 2],
                                ['caption' => '3.', 'value' => 3], ['caption' => '4.', 'value' => 4],
                                ['caption' => 'Last.', 'value' => 5],
                            ]]],
                        ['caption' => 'Day Type', 'name' => 'monthDayType', 'width' => '0px', 'add' => 0, 'visible' => false,
                            'edit' => ['type' => 'Select', 'options' => [
                                ['caption' => 'Day', 'value' => 0], ['caption' => 'Weekday', 'value' => 1],
                                ['caption' => 'Monday', 'value' => 2], ['caption' => 'Tuesday', 'value' => 3],
                                ['caption' => 'Wednesday', 'value' => 4], ['caption' => 'Thursday', 'value' => 5],
                                ['caption' => 'Friday', 'value' => 6], ['caption' => 'Saturday', 'value' => 7],
                                ['caption' => 'Sunday', 'value' => 8],
                            ]]],
                        ['caption' => 'Day', 'name' => 'yearDay', 'width' => '0px', 'add' => 1, 'visible' => false,
                            'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 31]],
                        ['caption' => 'Month', 'name' => 'yearMonth', 'width' => '0px', 'add' => 1, 'visible' => false,
                            'edit' => ['type' => 'Select', 'options' => [
                                ['caption' => 'January', 'value' => 1], ['caption' => 'February', 'value' => 2],
                                ['caption' => 'March', 'value' => 3], ['caption' => 'April', 'value' => 4],
                                ['caption' => 'May', 'value' => 5], ['caption' => 'June', 'value' => 6],
                                ['caption' => 'July', 'value' => 7], ['caption' => 'August', 'value' => 8],
                                ['caption' => 'September', 'value' => 9], ['caption' => 'October', 'value' => 10],
                                ['caption' => 'November', 'value' => 11], ['caption' => 'December', 'value' => 12],
                            ]]],
                        ['caption' => 'From', 'name' => 'dateFrom', 'width' => '0px',
                            'add' => ['year' => (int)date('Y'), 'month' => (int)date('m'), 'day' => (int)date('d')],
                            'visible' => false, 'edit' => ['type' => 'SelectDate']],
                        ['caption' => 'Unlimited', 'name' => 'unlimited', 'width' => '0px', 'add' => true, 'visible' => false,
                            'edit' => ['type' => 'CheckBox', 'onChange' => 'BIT_UIToggleUnlimited($id, $unlimited);']],
                        ['caption' => 'To', 'name' => 'dateTo', 'width' => '0px',
                            'add' => ['year' => (int)date('Y'), 'month' => (int)date('m'), 'day' => (int)date('d')],
                            'visible' => false, 'edit' => ['type' => 'SelectDate']],
                        ['caption' => 'On', 'name' => 'specificDate', 'width' => '0px',
                            'add' => ['year' => (int)date('Y'), 'month' => (int)date('m'), 'day' => (int)date('d')],
                            'visible' => false, 'edit' => ['type' => 'SelectDate']],
                        ['caption' => 'Time Pattern', 'name' => 'timePattern', 'width' => '0px', 'add' => 0, 'visible' => false,
                            'edit' => ['type' => 'Select', 'options' => [
                                ['caption' => 'At Specific Time', 'value' => 0], ['caption' => 'Every Second', 'value' => 1],
                                ['caption' => 'Every Minute', 'value' => 2], ['caption' => 'Every Hour', 'value' => 3],
                            ], 'onChange' => 'BIT_UIToggleTimePattern($id, $timePattern);']],
                        ['caption' => 'At', 'name' => 'timeValue', 'width' => '0px',
                            'add' => ['hour' => 6, 'minute' => 0, 'second' => 0],
                            'visible' => false, 'edit' => ['type' => 'SelectTime']],
                        ['caption' => 'Every', 'name' => 'timeInterval', 'width' => '0px', 'add' => 1, 'visible' => false,
                            'edit' => ['type' => 'NumberSpinner', 'minimum' => 1]],
                        ['caption' => 'From', 'name' => 'timeFrom', 'width' => '0px',
                            'add' => ['hour' => 6, 'minute' => 0, 'second' => 0],
                            'visible' => false, 'edit' => ['type' => 'SelectTime']],
                        ['caption' => 'To', 'name' => 'timeTo', 'width' => '0px',
                            'add' => ['hour' => 22, 'minute' => 0, 'second' => 0],
                            'visible' => false, 'edit' => ['type' => 'SelectTime']],
                    ],
                ],
            ],
        ];
    }

    private static function formatDuration(int $seconds, int $unit): string
    {
        if ($seconds === 0) {
            return '-';
        }
        $labels = [1 => 's', 60 => 'min', 3600 => 'h'];
        return $seconds . ' ' . ($labels[$unit] ?? 's');
    }

    private static function formatRuleActionDisplay(array $rule): string
    {
        $actions = $rule['actions'] ?? '';
        $hasActions = ($actions !== '' && $actions !== '{}' && $actions !== '[]');
        $fallback = (!empty($rule['fallbackEnabled']) && ($rule['fallbackActions'] ?? '') !== '' && ($rule['fallbackActions'] ?? '') !== '{}');
        if (!$hasActions && !$fallback) {
            return '-';
        }
        $parts = [];
        if ($hasActions) {
            $parts[] = self::t('Action');
        }
        if ($fallback) {
            $parts[] = '| ' . self::t('Fallback');
        }
        return implode(' ', $parts);
    }

    private static function formatFormulaActionDisplay(array $output): string
    {
        $formula = $output['formula'] ?? '';
        if ($formula === '') {
            return '-';
        }
        $display = $formula;
        if (!empty($output['fallbackFormulaEnabled']) && ($output['fallbackFormula'] ?? '') !== '') {
            $display .= ' | ' . $output['fallbackFormula'];
        }
        return $display;
    }

    private static function computeRuleStatus(array $rule): string
    {
        if (empty($rule['active'])) {
            return self::t('Inactive');
        }
        $actions = $rule['actions'] ?? '';
        if ($actions === '' || $actions === '{}' || $actions === '[]') {
            return self::t('No action');
        }
        return 'OK';
    }

    private static function computeFormulaStatus(array $output, array $allAliases): string
    {
        if (empty($output['active'])) {
            return self::t('Inactive');
        }
        $formula = $output['formula'] ?? '';
        if ($formula === '') {
            return self::t('No formula defined');
        }
        if (!ProLoader::has('formula')) {
            return 'OK';
        }
        $error = ProLoader::get('formula')->validate($formula, $allAliases);
        if ($error !== '') {
            return $error;
        }
        if (!empty($output['fallbackFormulaEnabled']) && ($output['fallbackFormula'] ?? '') !== '') {
            $fbError = ProLoader::get('formula')->validate($output['fallbackFormula'], $allAliases);
            if ($fbError !== '') {
                return 'Fallback: ' . $fbError;
            }
        }
        return 'OK';
    }

    private static function buildRuleElements(array $rules = [], int $ruleEvaluation = 1): array
    {
        $values    = [];
        $seenNames = [];
        foreach ($rules as $rule) {
            $name  = $rule['name'] ?? '';
            $color = '#FFFFFF';
            if ($name !== '' && in_array($name, $seenNames, true)) {
                $color = '#FFCCCC';
            }
            if ($name !== '') {
                $seenNames[] = $name;
            }
            $status = self::computeRuleStatus($rule);
            if ($name !== '' && in_array($name, array_slice($seenNames, 0, -1), true)) {
                $status = self::t('Duplicate name');
            }
            $values[] = array_merge($rule, [
                'rowColor'        => $color,
                'delayDisplay'    => self::formatDuration((int)($rule['delaySeconds'] ?? 0), (int)($rule['delayUnit'] ?? 1)),
                'cooldownDisplay' => self::formatDuration((int)($rule['cooldownSeconds'] ?? 0), (int)($rule['cooldownUnit'] ?? 1)),
                'intervalDisplay' => self::formatDuration((int)($rule['intervalSeconds'] ?? 0), (int)($rule['intervalUnit'] ?? 1)),
                'ruleStatus'      => $status,
            ]);
        }

        $maxRules = RuleEvaluator::getMaxRules();
        $ruleAtLimit = count($rules) >= $maxRules;
        $maxRulesDisplay = ProLoader::has('limiter') ? self::t('unlimited') : (string)$maxRules;
        $rulesCaption = self::t('Rules') . ' (' . count($rules) . ' / ' . $maxRulesDisplay . ')';

        return [
            [
                'type'        => 'List',
                'name'        => 'Rules',
                'caption'     => $rulesCaption,
                'add'         => !$ruleAtLimit,
                'delete'      => true,
                'changeOrder' => true,
                'rowCount'    => 5,
                'values'      => $values,
                'columns'     => [
                    ['caption' => 'Active', 'name' => 'active', 'width' => '50px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'Name', 'name' => 'name', 'width' => 'auto', 'add' => 'New Rule', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Condition', 'name' => 'conditions', 'width' => '200px', 'add' => '[]', 'edit' => ['type' => 'SelectCondition', 'multi' => true]],
                    ['caption' => '', 'name' => 'delaySeconds', 'width' => '0px', 'add' => 0, 'visible' => false, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0]],
                    ['caption' => '', 'name' => 'delayUnit', 'width' => '0px', 'add' => 1, 'visible' => false, 'edit' => ['type' => 'Select', 'options' => [['caption' => 'Sec.', 'value' => 1], ['caption' => 'Min.', 'value' => 60], ['caption' => 'Hr.', 'value' => 3600]]]],
                    ['caption' => '', 'name' => 'heatupResetOnInterruption', 'width' => '0px', 'add' => true, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => '', 'name' => 'cooldownSeconds', 'width' => '0px', 'add' => 0, 'visible' => false, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0]],
                    ['caption' => '', 'name' => 'cooldownUnit', 'width' => '0px', 'add' => 1, 'visible' => false, 'edit' => ['type' => 'Select', 'options' => [['caption' => 'Sec.', 'value' => 1], ['caption' => 'Min.', 'value' => 60], ['caption' => 'Hr.', 'value' => 3600]]]],
                    ['caption' => '', 'name' => 'cooldownResetOnReactivation', 'width' => '0px', 'add' => true, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => '', 'name' => 'intervalSeconds', 'width' => '0px', 'add' => 0, 'visible' => false, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0]],
                    ['caption' => '', 'name' => 'intervalUnit', 'width' => '0px', 'add' => 1, 'visible' => false, 'edit' => ['type' => 'Select', 'options' => [['caption' => 'Sec.', 'value' => 1], ['caption' => 'Min.', 'value' => 60], ['caption' => 'Hr.', 'value' => 3600]]]],
                    ['caption' => 'Heatup', 'name' => 'delayDisplay', 'width' => '80px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => 'Cooldown', 'name' => 'cooldownDisplay', 'width' => '80px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => 'Interval', 'name' => 'intervalDisplay', 'width' => '80px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => 'Status', 'name' => 'ruleStatus', 'width' => '100px', 'add' => 'OK'],
                    ['caption' => 'Actions', 'name' => 'actions', 'width' => '0px', 'add' => '{}', 'visible' => false, 'edit' => ['type' => 'SelectAction', 'multi' => true]],
                    ['caption' => '', 'name' => 'fallbackEnabled', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => '', 'name' => 'fallbackActions', 'width' => '0px', 'add' => '{}', 'visible' => false, 'edit' => ['type' => 'SelectAction', 'multi' => true]],
                ],
                'form' => ['return BIT_UIGetRulePopupForm($id, $Rules);'],
            ],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'RuleEvaluation', 'caption' => 'Evaluation',
                    'options' => [
                        ['caption' => 'First matching rule wins', 'value' => 0],
                        ['caption' => 'Execute all matching rules', 'value' => 1],
                    ],
                    'onChange' => 'BIT_UIToggleRuleSkip($id, $RuleEvaluation);'],
                ['type' => 'CheckBox', 'name' => 'RuleSkipHeatup', 'caption' => self::t('Skip heatup'), 'visible' => $ruleEvaluation === 0 && ProLoader::has('timing')],
                ['type' => 'CheckBox', 'name' => 'RuleSkipCooldown', 'caption' => self::t('Skip cooldown'), 'visible' => $ruleEvaluation === 0 && ProLoader::has('timing')],
                ['type' => 'CheckBox', 'name' => 'RuleSkipInterval', 'caption' => self::t('Skip interval'), 'visible' => $ruleEvaluation === 0 && ProLoader::has('timing')],
            ]],
        ];
    }

    private static function buildFormulaElements(array $triggers, array $formulaOutputs = [], int $formulaEvaluation = 1): array
    {
        $aliases = implode(', ', array_filter(array_column($triggers, 'alias')));

        $allAliases = array_filter(array_column($triggers, 'alias'));
        foreach ($formulaOutputs as $o) {
            if (($o['alias'] ?? '') !== '') {
                $allAliases[] = $o['alias'];
            }
        }

        $values          = [];
        $seenOutputAliases = [];
        foreach ($formulaOutputs as $output) {
            $formula = $output['formula'] ?? '';
            $alias   = $output['alias'] ?? '';

            if (empty($output['active'])) {
                $status = self::t('Formula inactive');
                $color  = '#EEEEEE';
            } elseif ($alias !== '' && in_array($alias, $seenOutputAliases, true)) {
                $status = self::t('Alias must be unique');
                $color  = '#FFCCCC';
            } elseif ($formula === '') {
                $status = self::t('Formula syntax error');
                $color  = '#FFEECC';
            } else {
                $error  = ProLoader::get('formula')->validate($formula, $allAliases);
                $status = $error === '' ? 'OK' : $error;
                $color  = $error === '' ? '#FFFFFF' : '#FFCCCC';

                if ($error === '' && !empty($output['fallbackFormulaEnabled'])) {
                    $fallbackFormula = $output['fallbackFormula'] ?? '';
                    if ($fallbackFormula !== '') {
                        $fallbackError = ProLoader::get('formula')->validate($fallbackFormula, $allAliases);
                        if ($fallbackError !== '') {
                            $status = 'Fallback: ' . $fallbackError;
                            $color  = '#FFCCCC';
                        }
                    }
                }
            }

            if ($alias !== '') {
                $seenOutputAliases[] = $alias;
            }

            $values[] = array_merge($output, [
                'formulaStatus'   => $status,
                'rowColor'        => $color,
                'delayDisplay'    => self::formatDuration((int)($output['delaySeconds'] ?? 0), (int)($output['delayUnit'] ?? 1)),
                'cooldownDisplay' => self::formatDuration((int)($output['cooldownSeconds'] ?? 0), (int)($output['cooldownUnit'] ?? 1)),
                'intervalDisplay' => self::formatDuration((int)($output['intervalSeconds'] ?? 0), (int)($output['intervalUnit'] ?? 1)),
            ]);
        }

        return [
            [
                'type'        => 'List',
                'name'        => 'FormulaOutputs',
                'caption'     => 'Output Variables',
                'add'         => true,
                'delete'      => true,
                'changeOrder' => true,
                'rowCount'    => 4,
                'values'      => $values,
                'columns'     => [
                    ['caption' => 'Active', 'name' => 'active', 'width' => '50px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => 'Output Variable', 'name' => 'variableID', 'width' => '250px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Alias', 'name' => 'alias', 'width' => '120px', 'add' => '$out', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Formula', 'name' => 'formula', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Condition', 'name' => 'conditions', 'width' => '200px', 'add' => '[]', 'edit' => ['type' => 'SelectCondition', 'multi' => true]],
                    ['caption' => '', 'name' => 'delaySeconds', 'width' => '0px', 'add' => 0, 'visible' => false, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0]],
                    ['caption' => '', 'name' => 'delayUnit', 'width' => '0px', 'add' => 1, 'visible' => false, 'edit' => ['type' => 'Select', 'options' => [['caption' => 'Sec.', 'value' => 1], ['caption' => 'Min.', 'value' => 60], ['caption' => 'Hr.', 'value' => 3600]]]],
                    ['caption' => '', 'name' => 'heatupResetOnInterruption', 'width' => '0px', 'add' => true, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => '', 'name' => 'cooldownSeconds', 'width' => '0px', 'add' => 0, 'visible' => false, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0]],
                    ['caption' => '', 'name' => 'cooldownUnit', 'width' => '0px', 'add' => 1, 'visible' => false, 'edit' => ['type' => 'Select', 'options' => [['caption' => 'Sec.', 'value' => 1], ['caption' => 'Min.', 'value' => 60], ['caption' => 'Hr.', 'value' => 3600]]]],
                    ['caption' => '', 'name' => 'cooldownResetOnReactivation', 'width' => '0px', 'add' => true, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => '', 'name' => 'intervalSeconds', 'width' => '0px', 'add' => 0, 'visible' => false, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0]],
                    ['caption' => '', 'name' => 'intervalUnit', 'width' => '0px', 'add' => 1, 'visible' => false, 'edit' => ['type' => 'Select', 'options' => [['caption' => 'Sec.', 'value' => 1], ['caption' => 'Min.', 'value' => 60], ['caption' => 'Hr.', 'value' => 3600]]]],
                    ['caption' => 'Heatup', 'name' => 'delayDisplay', 'width' => '80px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => 'Cooldown', 'name' => 'cooldownDisplay', 'width' => '80px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => 'Interval', 'name' => 'intervalDisplay', 'width' => '80px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => '', 'name' => 'fallbackFormulaEnabled', 'width' => '0px', 'add' => false, 'visible' => false, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => '', 'name' => 'fallbackFormula', 'width' => '0px', 'add' => '', 'visible' => false, 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Status', 'name' => 'formulaStatus', 'width' => '150px', 'add' => ''],
                ],
                'form' => ['return BIT_UIGetFormulaPopupForm($id, $FormulaOutputs);'],
            ],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'FormulaEvaluation', 'caption' => 'Evaluation',
                    'options' => [
                        ['caption' => 'First matching formula wins', 'value' => 0],
                        ['caption' => 'Execute all matching formulas', 'value' => 1],
                    ],
                    'onChange' => 'BIT_UIToggleFormulaSkip($id, $FormulaEvaluation);'],
                ['type' => 'CheckBox', 'name' => 'FormulaSkipHeatup', 'caption' => self::t('Skip heatup'), 'visible' => $formulaEvaluation === 0 && ProLoader::has('timing')],
                ['type' => 'CheckBox', 'name' => 'FormulaSkipCooldown', 'caption' => self::t('Skip cooldown'), 'visible' => $formulaEvaluation === 0 && ProLoader::has('timing')],
                ['type' => 'CheckBox', 'name' => 'FormulaSkipInterval', 'caption' => self::t('Skip interval'), 'visible' => $formulaEvaluation === 0 && ProLoader::has('timing')],
            ]],
        ];
    }

    private static function buildExpertElements(array $triggers): array
    {
        $aliases = implode(', ', array_filter(array_column($triggers, 'alias')));

        return [
            [
                'type'        => 'List',
                'name'        => 'ExpertOutputs',
                'caption'     => 'Output Variables (optional)',
                'add'         => true,
                'delete'      => true,
                'rowCount'    => 3,
                'columns'     => [
                    ['caption' => 'Output Variable', 'name' => 'variableID', 'width' => '250px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                    ['caption' => 'Alias', 'name' => 'alias', 'width' => '120px', 'add' => '$out', 'edit' => ['type' => 'ValidationTextBox']],
                ],
            ],
            ['type' => 'Label', 'caption' => self::t('Available trigger aliases:') . ' ' . ($aliases ?: self::t('(none defined)'))],
            ['type' => 'ScriptEditor', 'name' => 'ExpertScript', 'caption' => 'Script', 'rowCount' => 10],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'ExpertInterval', 'caption' => 'Interval', 'minimum' => 0],
                ['type' => 'Select', 'name' => 'ExpertIntervalUnit', 'caption' => ' ', 'options' => [
                    ['caption' => 'Seconds', 'value' => 1],
                    ['caption' => 'Minutes', 'value' => 60],
                    ['caption' => 'Hours', 'value' => 3600],
                ]],
            ]],
        ];
    }

    private static function buildCombinedElements(array $rules, array $formulaOutputs, array $combinedOrder, int $combinedEvaluation): array
    {
        $allAliases = array_filter(array_merge(
            array_column($rules, 'name'),
            array_column($formulaOutputs, 'alias')
        ));

        $values = [];
        foreach ($combinedOrder as $position => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ref = $entry['ref'] ?? '';
            if (!str_contains($ref, ':')) {
                continue;
            }
            [$type, $indexStr] = explode(':', $ref, 2);
            $index = (int)$indexStr;

            if ($type === 'rule' && isset($rules[$index])) {
                $rule = $rules[$index];
                $ruleStatus = self::computeRuleStatus($rule);
                $values[] = array_merge($entry, $rule, [
                    'position'       => $position + 1,
                    'entryType'      => self::t('Rule'),
                    'entryName'      => $rule['name'] ?? self::t('Rule') . ' ' . ($index + 1),
                    'actionDisplay'  => self::formatRuleActionDisplay($rule),
                    'delayDisplay'   => self::formatDuration((int)($rule['delaySeconds'] ?? 0), (int)($rule['delayUnit'] ?? 1)),
                    'cooldownDisplay' => self::formatDuration((int)($rule['cooldownSeconds'] ?? 0), (int)($rule['cooldownUnit'] ?? 1)),
                    'intervalDisplay' => self::formatDuration((int)($rule['intervalSeconds'] ?? 0), (int)($rule['intervalUnit'] ?? 1)),
                    'statusDisplay'  => $ruleStatus,
                    'rowColor'       => empty($rule['active']) ? '#EEEEEE' : ($ruleStatus === 'OK' ? '#FFFFFF' : '#FFEECC'),
                ]);
            } elseif ($type === 'formula' && isset($formulaOutputs[$index])) {
                $output = $formulaOutputs[$index];
                $status = self::computeFormulaStatus($output, $allAliases);
                $color = empty($output['active']) ? '#EEEEEE' : ($status === 'OK' ? '#FFFFFF' : '#FFCCCC');
                $values[] = array_merge($entry, $output, [
                    'position'       => $position + 1,
                    'entryType'      => self::t('Formula'),
                    'entryName'      => $output['alias'] ?? self::t('Formula') . ' ' . ($index + 1),
                    'actionDisplay'  => self::formatFormulaActionDisplay($output),
                    'delayDisplay'   => self::formatDuration((int)($output['delaySeconds'] ?? 0), (int)($output['delayUnit'] ?? 1)),
                    'cooldownDisplay' => self::formatDuration((int)($output['cooldownSeconds'] ?? 0), (int)($output['cooldownUnit'] ?? 1)),
                    'intervalDisplay' => self::formatDuration((int)($output['intervalSeconds'] ?? 0), (int)($output['intervalUnit'] ?? 1)),
                    'statusDisplay'  => $status,
                    'rowColor'       => $color,
                ]);
            }
        }

        return [
            [
                'type'        => 'List',
                'name'        => 'CombinedOrder',
                'caption'     => self::t('Execution Order'),
                'add'         => false,
                'delete'      => false,
                'changeOrder' => true,
                'rowCount'    => 8,
                'values'      => $values,
                'form'        => ['return BIT_UIGetCombinedPopupForm($id, $CombinedOrder);'],
                'columns'     => [
                    ['caption' => '#', 'name' => 'position', 'width' => '30px', 'add' => 0],
                    ['caption' => self::t('Active'), 'name' => 'active', 'width' => '50px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ['caption' => self::t('Type'), 'name' => 'entryType', 'width' => '70px', 'add' => ''],
                    ['caption' => self::t('Name'), 'name' => 'entryName', 'width' => '120px', 'add' => ''],
                    ['caption' => self::t('Action / Formula'), 'name' => 'actionDisplay', 'width' => 'auto', 'add' => ''],
                    ['caption' => self::t('Condition'), 'name' => 'conditions', 'width' => '150px', 'add' => '[]'],
                    ['caption' => self::t('Heatup'), 'name' => 'delayDisplay', 'width' => '70px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => self::t('Cooldown'), 'name' => 'cooldownDisplay', 'width' => '70px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => self::t('Interval'), 'name' => 'intervalDisplay', 'width' => '70px', 'add' => '-', 'visible' => ProLoader::has('timing')],
                    ['caption' => self::t('Status'), 'name' => 'statusDisplay', 'width' => '100px', 'add' => ''],
                    ['caption' => '', 'name' => 'ref', 'width' => '0px', 'add' => '', 'visible' => false, 'edit' => ['type' => 'ValidationTextBox']],
                ],
            ],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'CombinedEvaluation', 'caption' => self::t('Evaluation'),
                    'options' => [
                        ['caption' => self::t('First match wins'), 'value' => 0],
                        ['caption' => self::t('Execute all matching'), 'value' => 1],
                    ],
                    'onChange' => 'BIT_UIToggleCombinedSkip($id, $CombinedEvaluation);'],
                ['type' => 'CheckBox', 'name' => 'CombinedSkipHeatup', 'caption' => self::t('Skip heatup'), 'visible' => $combinedEvaluation === 0 && ProLoader::has('timing')],
                ['type' => 'CheckBox', 'name' => 'CombinedSkipCooldown', 'caption' => self::t('Skip cooldown'), 'visible' => $combinedEvaluation === 0 && ProLoader::has('timing')],
                ['type' => 'CheckBox', 'name' => 'CombinedSkipInterval', 'caption' => self::t('Skip interval'), 'visible' => $combinedEvaluation === 0 && ProLoader::has('timing')],
            ]],
        ];
    }

    private static function buildActions(): array
    {
        return [
            ['type' => 'Button', 'caption' => 'Evaluate now', 'onClick' => "BIT_Evaluate(\$id); echo 'Evaluation complete.';"],
        ];
    }

    public static function buildRulePopupForm(array $row): array
    {
        $fallbackEnabled = !empty($row['fallbackEnabled']);

        return [
            ['type' => 'ValidationTextBox', 'name' => 'name', 'caption' => 'Name'],
            ['type' => 'CheckBox', 'name' => 'active', 'caption' => 'Active'],
            ['type' => 'ExpansionPanel', 'caption' => 'Conditions', 'expanded' => false, 'items' => [
                ['type' => 'SelectCondition', 'name' => 'conditions', 'multi' => true, 'caption' => 'Conditions'],
                ['type' => 'RowLayout', 'visible' => ProLoader::has('timing'), 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'delaySeconds', 'caption' => self::t('Heatup'), 'minimum' => 0],
                    ['type' => 'Select', 'name' => 'delayUnit', 'caption' => ' ', 'options' => [
                        ['caption' => self::t('Seconds'), 'value' => 1], ['caption' => self::t('Minutes'), 'value' => 60], ['caption' => self::t('Hours'), 'value' => 3600],
                    ]],
                    ['type' => 'CheckBox', 'name' => 'heatupResetOnInterruption', 'caption' => self::t('Reset on interruption')],
                ]],
                ['type' => 'RowLayout', 'visible' => ProLoader::has('timing'), 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'cooldownSeconds', 'caption' => self::t('Cooldown'), 'minimum' => 0],
                    ['type' => 'Select', 'name' => 'cooldownUnit', 'caption' => ' ', 'options' => [
                        ['caption' => self::t('Seconds'), 'value' => 1], ['caption' => self::t('Minutes'), 'value' => 60], ['caption' => self::t('Hours'), 'value' => 3600],
                    ]],
                    ['type' => 'CheckBox', 'name' => 'cooldownResetOnReactivation', 'caption' => self::t('Reset on reactivation')],
                ]],
            ]],
            ['type' => 'SelectAction', 'name' => 'actions', 'multi' => true, 'caption' => 'Actions'],
            ['type' => 'CheckBox', 'name' => 'fallbackEnabled', 'caption' => self::t('Fallback Actions'), 'visible' => ProLoader::has('limiter'),
                'onChange' => 'BIT_UIToggleFallbackActions($id, $fallbackEnabled);'],
            ['type' => 'SelectAction', 'name' => 'fallbackActions', 'multi' => true, 'caption' => ' ',
                'visible' => $fallbackEnabled && ProLoader::has('limiter')],
            ['type' => 'RowLayout', 'visible' => ProLoader::has('timing'), 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'intervalSeconds', 'caption' => self::t('Interval'), 'minimum' => 0],
                ['type' => 'Select', 'name' => 'intervalUnit', 'caption' => ' ', 'options' => [
                    ['caption' => self::t('Seconds'), 'value' => 1], ['caption' => self::t('Minutes'), 'value' => 60], ['caption' => self::t('Hours'), 'value' => 3600],
                ]],
            ]],
        ];
    }

    public static function buildFormulaPopupForm(array $row, array $triggerAliases): array
    {
        $fallbackEnabled = !empty($row['fallbackFormulaEnabled']);
        $fallbackFormula = $row['fallbackFormula'] ?? '';
        $fallbackFormulaError = ($fallbackEnabled && $fallbackFormula !== '')
            ? ProLoader::get('formula')->validate($fallbackFormula, array_merge(
                array_filter($triggerAliases),
                array_filter([$row['alias'] ?? ''])
            ))
            : '';
        $aliases = implode(', ', array_filter($triggerAliases)) ?: self::t('(none defined)');

        return [
            ['type' => 'CheckBox', 'name' => 'active', 'caption' => 'Active'],
            ['type' => 'SelectVariable', 'name' => 'variableID', 'caption' => 'Output Variable'],
            ['type' => 'ValidationTextBox', 'name' => 'alias', 'caption' => 'Alias',
                'validate' => '^\$[a-zA-Z_][a-zA-Z0-9_]*$'],
            ['type' => 'ExpansionPanel', 'caption' => 'Conditions', 'expanded' => false, 'visible' => ProLoader::has('timing'), 'items' => [
                ['type' => 'SelectCondition', 'name' => 'conditions', 'multi' => true, 'caption' => 'Conditions'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'delaySeconds', 'caption' => self::t('Heatup'), 'minimum' => 0],
                    ['type' => 'Select', 'name' => 'delayUnit', 'caption' => ' ', 'options' => [
                        ['caption' => self::t('Seconds'), 'value' => 1], ['caption' => self::t('Minutes'), 'value' => 60], ['caption' => self::t('Hours'), 'value' => 3600],
                    ]],
                    ['type' => 'CheckBox', 'name' => 'heatupResetOnInterruption', 'caption' => self::t('Reset on interruption')],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'cooldownSeconds', 'caption' => self::t('Cooldown'), 'minimum' => 0],
                    ['type' => 'Select', 'name' => 'cooldownUnit', 'caption' => ' ', 'options' => [
                        ['caption' => self::t('Seconds'), 'value' => 1], ['caption' => self::t('Minutes'), 'value' => 60], ['caption' => self::t('Hours'), 'value' => 3600],
                    ]],
                    ['type' => 'CheckBox', 'name' => 'cooldownResetOnReactivation', 'caption' => self::t('Reset on reactivation')],
                ]],
            ]],
            ['type' => 'ValidationTextBox', 'name' => 'formula', 'caption' => 'Formula',
                'validate' => '^.+$',
                'onChange' => 'BIT_UIValidateFormulaField($id, $formula);'],
            ['type' => 'Label', 'name' => 'formulaError', 'caption' => '', 'foreground' => '0xFF0000'],
            ['type' => 'CheckBox', 'name' => 'fallbackFormulaEnabled', 'caption' => self::t('Fallback Formula'), 'visible' => ProLoader::has('limiter'),
                'onChange' => 'BIT_UIToggleFallbackFormula($id, $fallbackFormulaEnabled);'],
            ['type' => 'ValidationTextBox', 'name' => 'fallbackFormula', 'caption' => ' ',
                'visible' => $fallbackEnabled && ProLoader::has('limiter'),
                'onChange' => 'BIT_UIValidateFallbackFormulaField($id, $fallbackFormula);'],
            ['type' => 'Label', 'name' => 'fallbackFormulaError',
                'caption' => $fallbackFormulaError,
                'foreground' => '0xFF0000',
                'visible' => $fallbackFormulaError !== '' && ProLoader::has('limiter')],
            ['type' => 'RowLayout', 'visible' => ProLoader::has('timing'), 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'intervalSeconds', 'caption' => self::t('Interval'), 'minimum' => 0],
                ['type' => 'Select', 'name' => 'intervalUnit', 'caption' => ' ', 'options' => [
                    ['caption' => self::t('Seconds'), 'value' => 1], ['caption' => self::t('Minutes'), 'value' => 60], ['caption' => self::t('Hours'), 'value' => 3600],
                ]],
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Help & Examples', 'expanded' => false, 'items' => [
                ['type' => 'Label', 'caption' => self::t('Available aliases (triggers):') . ' ' . $aliases],
                ['type' => 'Label', 'caption' => self::t('Output alias is also available — contains the current value of the output variable.')],
                ['type' => 'Label', 'caption' => self::t('Functions:') . ' min(), max(), abs(), round(), floor(), ceil(), clamp(min,val,max), avg(...), sum(...)'],
                ['type' => 'Label', 'caption' => self::t('Operators:') . ' + - * / % ( )'],
                ['type' => 'Label', 'caption' => self::t('Examples:')],
                ['type' => 'Label', 'caption' => '  $var * 2'],
                ['type' => 'Label', 'caption' => '  round($var, 1)'],
                ['type' => 'Label', 'caption' => '  clamp(0, $var, 100)'],
                ['type' => 'Label', 'caption' => '  avg($var, 20)'],
                ['type' => 'Label', 'caption' => '  $out + 1   (' . self::t('increment counter') . ')'],
            ]],
        ];
    }

    private static function buildStatus(): array
    {
        return [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Module active'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Module inactive'],
            ['code' => 200, 'icon' => 'error', 'caption' => 'No triggers defined'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'Invalid alias detected'],
            ['code' => 202, 'icon' => 'error', 'caption' => 'Formula syntax error'],
            ['code' => 203, 'icon' => 'error', 'caption' => 'Script syntax error'],
            ['code' => 204, 'icon' => 'error', 'caption' => 'Referenced variable not found'],
            ['code' => 205, 'icon' => 'error', 'caption' => 'Duplicate rule name'],
            ['code' => 206, 'icon' => 'error', 'caption' => 'Trigger limit exceeded'],
            ['code' => 207, 'icon' => 'error', 'caption' => 'Rule limit exceeded'],
            ['code' => 208, 'icon' => 'error', 'caption' => 'Formula mode requires Plus license'],
            ['code' => 209, 'icon' => 'error', 'caption' => 'Expert mode requires Pro license'],
            ['code' => 210, 'icon' => 'error', 'caption' => 'Combined mode requires Pro license'],
        ];
    }

    private static function buildLicenseStatus(): array
    {
        $tier = ProLoader::tier();

        if ($tier === 'community') {
            return [
                'type' => 'ExpansionPanel',
                'caption' => self::t('License') . ': Community',
                'expanded' => false,
                'items' => [
                    ['type' => 'Label', 'caption' => self::t('managed by bitCONTROL License instance'), 'italic' => true],
                    ['type' => 'Label', 'caption' => ''],
                    ['type' => 'Label', 'caption' => self::t('Available with Plus:')],
                    ['type' => 'Label', 'caption' => '• ' . self::t('Unlimited triggers and rules')],
                    ['type' => 'Label', 'caption' => '• ' . self::t('Formula mode')],
                    ['type' => 'Label', 'caption' => '• ' . self::t('Heatup / Cooldown / Interval')],
                    ['type' => 'Label', 'caption' => '• ' . self::t('Fallback formulas and actions')],
                    ['type' => 'Label', 'caption' => ''],
                    ['type' => 'Label', 'caption' => self::t('Available with Pro:')],
                    ['type' => 'Label', 'caption' => '• ' . self::t('Expert mode (PHP scripting)')],
                    ['type' => 'Label', 'caption' => '• ' . self::t('Combined mode (priority ordering)')],
                    ['type' => 'Label', 'caption' => '• ' . self::t('Mode chaining')],
                ],
            ];
        }

        return [
            'type' => 'Label',
            'caption' => sprintf('%s: %s ✓', self::t('License'), ucfirst($tier)),
            'bold' => true,
            'color' => 0x00AA00,
        ];
    }
}
