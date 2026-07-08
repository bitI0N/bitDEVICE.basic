<?php

declare(strict_types=1);

require_once __DIR__ . '/../bitCONTROLbasic/libs/ProLoader.php';
require_once __DIR__ . '/libs/LicenseManager.php';

class bitCONTROLLicense extends IPSModuleStrict
{
    private const REVALIDATION_INTERVAL_MS = 7 * 24 * 60 * 60 * 1000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('LicenseKey', '');
        $this->RegisterPropertyString('ServerUrl', '');
        $this->RegisterPropertyString('TestLicensee', '');
        $this->RegisterPropertyInteger('SimulationTier', 0);
        $this->RegisterTimer('LicenseRevalidation', 0, 'BIT_Revalidate($_IPS[\'TARGET\']);');

        $this->RegisterVariableBoolean('Active', $this->Translate('Active'), '~Switch', 0);
        $this->EnableAction('Active');
    }

    private function hasSimulator(): bool
    {
        return file_exists(__DIR__ . '/../bitLICENSEsimulator/SimulationProvider.php');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('Active', false);
            $this->deactivatePro();
            $this->SetStatus(104);
            return;
        }

        $this->SetValue('Active', true);
        $this->bootLicense();
    }

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $action = $data['Action'] ?? '';

        return match ($action) {
            'GetTier' => json_encode(['tier' => ProLoader::tier()]),
            'GetStatus' => json_encode(($this->createLicenseManager())->getStatus()),
            default => json_encode(['error' => 'Unknown action']),
        };
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Active':
                $this->SetValue('Active', $Value);
                IPS_SetProperty($this->InstanceID, 'Active', $Value);
                IPS_ApplyChanges($this->InstanceID);
                break;
            case 'LicenseActivate':
                $this->handleActivation((string)$Value);
                break;
            case 'LicenseDeactivate':
                $this->handleDeactivation();
                break;
            case 'LicenseRefresh':
                $this->handleRevalidation();
                break;
        }
    }

    public function Revalidate(): void
    {
        $this->handleRevalidation();
    }

    public function GetConfigurationForm(): string
    {
        $elements = [
            ['type' => 'CheckBox', 'name' => 'Active', 'caption' => $this->t('Active')],
        ];

        if ($this->hasSimulator()) {
            $elements[] = ['type' => 'Select', 'name' => 'SimulationTier', 'caption' => $this->t('Simulation'), 'options' => [
                ['caption' => '— ' . $this->t('disabled') . ' —', 'value' => 0],
                ['caption' => 'Plus', 'value' => 1],
                ['caption' => 'Pro', 'value' => 2],
            ]];
        }

        $lm = $this->createLicenseManager();
        $status = $lm->getStatus();
        $elements = array_merge($elements, $this->buildStatusElements($status));
        $actions = $this->buildActions($status);

        return json_encode([
            'elements' => $elements,
            'actions' => $actions,
            'status' => json_decode(file_get_contents(__DIR__ . '/form.json'), true)['status'],
        ]);
    }


    private function bootLicense(): void
    {
        if ($this->hasSimulator()) {
            require_once __DIR__ . '/../bitLICENSEsimulator/SimulationProvider.php';
            $simTier = $this->ReadPropertyInteger('SimulationTier');
            if ($simTier > 0) {
                SimulationProvider::activate($simTier, $this->getDataPath());
                ProLoader::reset();
                ProLoader::boot($this->getDataPath(), true);
                $this->SetSummary(SimulationProvider::getTierName($simTier) . ' (Sim)');
                $this->SetStatus(102);
                $this->SetTimerInterval('LicenseRevalidation', 0);
                return;
            }
            if (!$this->hasRealLicense()) {
                SimulationProvider::deactivate($this->getDataPath()); // @phpstan-ignore staticMethod.notFound
                ProLoader::reset();
            }
        }

        $lm = $this->createLicenseManager();
        $status = $lm->validate();

        match ($status['state']) {
            'active', 'grace' => $this->activatePro($status),
            default => $this->deactivatePro(),
        };
    }

    private function activatePro(array $status): void
    {
        ProLoader::boot($this->getDataPath());

        // Bind loaded capabilities to the signed token: the package on disk must
        // not grant a higher tier than the license permits. Blocks unlocking Pro
        // by dropping fabricated capability files into data/pro/ under a Plus token.
        $loadedTier = ProLoader::tier();
        $licensedTier = $status['tier'] ?? 'community';
        if ($this->tierRank($loadedTier) > $this->tierRank($licensedTier)) {
            $this->SendDebug('License', sprintf('Tier mismatch: package=%s exceeds license=%s — refusing', $loadedTier, $licensedTier), 0);
            $this->deactivatePro();
            return;
        }

        $tier = ProLoader::tier();
        $lm = $this->createLicenseManager();
        $fullStatus = $lm->getStatus();
        $summary = ucfirst($tier);
        if (!empty($fullStatus['licensee'])) {
            $summary .= ' (' . $fullStatus['licensee'] . ')';
        }
        $this->SetSummary($summary);
        $this->SetStatus(102);
        $this->SetTimerInterval('LicenseRevalidation', self::REVALIDATION_INTERVAL_MS);

        if ($status['state'] === 'grace') {
            $this->SendDebug('License', sprintf('Grace period: %d days remaining', $status['daysLeft'] ?? 0), 0);
        }
    }

    private function deactivatePro(): void
    {
        ProLoader::reset();

        $this->SetSummary('Community');
        $this->SetStatus(102);
        $this->SetTimerInterval('LicenseRevalidation', 0);
    }

    private function tierRank(string $tier): int
    {
        return match ($tier) {
            'pro' => 2,
            'plus' => 1,
            default => 0,
        };
    }

    private function handleActivation(string $key): void
    {
        $lm = $this->createLicenseManager();
        $result = $lm->activate($key, $this->getLicensee());

        if ($result['success']) {
            ProLoader::reset();
            $this->bootLicense();
            $this->SendDebug('License', 'Activated: ' . ($result['tier'] ?? ''), 0);
        } else {
            $this->SendDebug('License', 'Activation failed: ' . ($result['error'] ?? ''), 0);
            $this->SetStatus(201);
        }

        $this->ReloadForm();
    }

    private function handleDeactivation(): void
    {
        $lm = $this->createLicenseManager();
        $lm->deactivate($this->getLicensee());

        ProLoader::reset();
        $this->deactivatePro();
        $this->SendDebug('License', 'Deactivated', 0);

        $this->ReloadForm();
    }

    private function handleRevalidation(): void
    {
        $lm = $this->createLicenseManager();
        $result = $lm->revalidate($this->getLicensee());

        if (isset($result['success']) && $result['success']) {
            if (!empty($result['update_available'])) {
                ProLoader::reset();
                $this->bootLicense();
                $this->SendDebug('License', 'Updated to new version', 0);
            }
        } elseif (!empty($result['revoked'])) {
            ProLoader::reset();
            $this->deactivatePro();
            $this->SendDebug('License', 'License revoked by server — downgraded to Community', 0);
        } else {
            $this->SendDebug('License', 'Revalidation failed: ' . ($result['error'] ?? ''), 0);
        }

        $this->ReloadForm();
    }

    private function hasRealLicense(): bool
    {
        return file_exists($this->getDataPath() . '/license.json');
    }

    private function getDataPath(): string
    {
        return __DIR__ . '/data';
    }

    private function getLicensee(): string
    {
        $testLicensee = $this->ReadPropertyString('TestLicensee');
        return $testLicensee !== '' ? $testLicensee : IPS_GetLicensee();
    }

    private function createLicenseManager(): LicenseManager
    {
        $serverUrl = $this->ReadPropertyString('ServerUrl');
        if ($serverUrl === '') {
            $serverUrl = $this->getEnv('BITHOME_LICENSE_VALIDATION');
        }
        return new LicenseManager(
            $this->getDataPath(),
            null,
            $serverUrl !== '' ? $serverUrl : null,
            $this->getLicensee()
        );
    }

    private function getEnv(string $key): string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        $envFile = __DIR__ . '/../.env.local';
        if (!file_exists($envFile)) {
            return '';
        }
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, $key . '=')) {
                return substr($line, strlen($key) + 1);
            }
        }
        return '';
    }

    private function t(string $s): string
    {
        return $this->Translate($s);
    }

    private function buildStatusElements(array $status): array
    {
        $elements = [];

        switch ($status['state']) {
            case 'active':
                $elements[] = ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Label', 'caption' => $this->Translate('Active License:'), 'bold' => true, 'width' => '130px'],
                    ['type' => 'Label', 'caption' => ucfirst($status['tier'] ?? 'community')],
                ]];
                if (!empty($status['features'])) {
                    $elements[] = ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Label', 'caption' => $this->Translate('Features:'), 'bold' => true, 'width' => '130px'],
                        ['type' => 'Label', 'caption' => implode(', ', $status['features'])],
                    ]];
                }
                $elements[] = ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Label', 'caption' => $this->Translate('Licensed to:'), 'bold' => true, 'width' => '130px'],
                    ['type' => 'Label', 'caption' => $status['licensee'] ?? ''],
                ]];
                if (!empty($status['updatesUntil'])) {
                    $elements[] = ['type' => 'RowLayout', 'items' => [
                        ['type' => 'Label', 'caption' => $this->Translate('Updates until:'), 'bold' => true, 'width' => '130px'],
                        ['type' => 'Label', 'caption' => $status['updatesUntil']],
                    ]];
                }
                break;

            case 'grace':
                $elements[] = ['type' => 'Label', 'caption' => sprintf('%s — %d %s', $this->Translate('Grace Period'), $status['daysLeft'] ?? 0, $this->Translate('days remaining')), 'bold' => true, 'color' => 0xCC8800];
                $elements[] = ['type' => 'Label', 'caption' => $this->Translate('License server unreachable. Features remain active temporarily.')];
                break;

            case 'expired':
                $elements[] = ['type' => 'Label', 'caption' => $this->Translate('License expired — running in Community mode'), 'bold' => true, 'color' => 0xCC0000];
                break;

            default:
                $elements[] = ['type' => 'Label', 'caption' => $this->Translate('Community Edition'), 'bold' => true];
                $elements[] = ['type' => 'Label', 'caption' => $this->Translate('Unlock Formula mode, unlimited triggers and rules, and more.'), 'italic' => true];
                break;
        }

        return $elements;
    }

    private function buildActions(array $status): array
    {
        $actions = [];

        if ($status['state'] === 'active') {
            $actions[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => $this->t('Check for Updates'), 'onClick' => 'IPS_RequestAction($id, "LicenseRefresh", "");'],
                ['type' => 'Button', 'caption' => $this->t('Deactivate'), 'onClick' => 'IPS_RequestAction($id, "LicenseDeactivate", "");'],
            ]];
        } elseif ($status['state'] === 'grace') {
            $actions[] = ['type' => 'Button', 'caption' => $this->t('Retry Now'), 'onClick' => 'IPS_RequestAction($id, "LicenseRefresh", "");'];
        } else {
            $actions[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'LicenseKey', 'caption' => $this->t('License Key'), 'width' => '350px'],
                ['type' => 'Button', 'caption' => $this->t('Activate'), 'onClick' => 'IPS_RequestAction($id, "LicenseActivate", $LicenseKey);'],
            ]];
        }

        return $actions;
    }
}
