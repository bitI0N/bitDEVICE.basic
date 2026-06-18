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
        $this->RegisterTimer('LicenseRevalidation', 0, 'BIT_Revalidate($_IPS[\'TARGET\']);');

        $this->RegisterVariableBoolean('Active', $this->Translate('Active'), '~Switch', 0);
        $this->EnableAction('Active');
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
            'GetStatus' => json_encode((new LicenseManager($this->getDataPath()))->getStatus()),
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
        $lm = new LicenseManager($this->getDataPath());
        $status = $lm->getStatus();

        $elements = [
            ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Active'],
        ];
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
        $lm = new LicenseManager($this->getDataPath());
        $status = $lm->validate();

        match ($status['state']) {
            'active', 'grace' => $this->activatePro($status),
            default => $this->deactivatePro(),
        };
    }

    private function activatePro(array $status): void
    {
        ProLoader::boot($this->getDataPath());

        $tier = ProLoader::tier();
        $this->SetSummary(ucfirst($tier) . ' — active');
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

    private function handleActivation(string $key): void
    {
        $lm = new LicenseManager($this->getDataPath());
        $result = $lm->activate($key, IPS_GetLicensee());

        if ($result['success']) {
            ProLoader::reset();
            $this->bootLicense();
            $this->SendDebug('License', 'Activated: ' . ($result['tier'] ?? ''), 0);
        } else {
            $this->SendDebug('License', 'Activation failed: ' . ($result['error'] ?? ''), 0);
            $this->SetStatus(201);
        }

        echo json_encode($result);
    }

    private function handleDeactivation(): void
    {
        $lm = new LicenseManager($this->getDataPath());
        $lm->deactivate();

        ProLoader::reset();
        $this->deactivatePro();
        $this->SendDebug('License', 'Deactivated', 0);

        echo json_encode(['success' => true]);
    }

    private function handleRevalidation(): void
    {
        $lm = new LicenseManager($this->getDataPath());
        $result = $lm->revalidate(IPS_GetLicensee());

        if (isset($result['success']) && $result['success']) {
            if (!empty($result['update_available'])) {
                ProLoader::reset();
                $this->bootLicense();
                $this->SendDebug('License', 'Updated to new version', 0);
            }
        } else {
            $this->SendDebug('License', 'Revalidation failed: ' . ($result['error'] ?? ''), 0);
        }

        echo json_encode($result);
    }

    private function getDataPath(): string
    {
        return __DIR__ . '/data';
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
                $elements[] = ['type' => 'Label', 'caption' => sprintf('%s — active', ucfirst($status['tier'] ?? 'community')), 'bold' => true, 'color' => 0x00AA00];
                $elements[] = ['type' => 'Label', 'caption' => sprintf('%s %s', $this->t('Licensed to:'), $status['licensee'] ?? '')];
                if (!empty($status['expires'])) {
                    $elements[] = ['type' => 'Label', 'caption' => sprintf('%s %s', $this->t('Updates until:'), $status['expires'])];
                }
                break;

            case 'grace':
                $elements[] = ['type' => 'Label', 'caption' => sprintf('%s — %d %s', $this->t('Grace Period'), $status['daysLeft'] ?? 0, $this->t('days remaining')), 'bold' => true, 'color' => 0xCC8800];
                $elements[] = ['type' => 'Label', 'caption' => $this->t('License server unreachable. Features remain active temporarily.')];
                break;

            case 'expired':
                $elements[] = ['type' => 'Label', 'caption' => $this->t('License expired — running in Community mode'), 'bold' => true, 'color' => 0xCC0000];
                break;

            default:
                $elements[] = ['type' => 'Label', 'caption' => $this->t('Community Edition'), 'bold' => true];
                $elements[] = ['type' => 'Label', 'caption' => $this->t('Unlock Formula mode, unlimited triggers and rules, and more.'), 'italic' => true];
                break;
        }

        return $elements;
    }

    private function buildActions(array $status): array
    {
        $actions = [];

        if ($status['state'] === 'active') {
            $actions[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'Button', 'caption' => $this->t('Check for Updates'), 'onClick' => 'BIT_RequestAction($id, "LicenseRefresh", "");'],
                ['type' => 'Button', 'caption' => $this->t('Deactivate'), 'onClick' => 'BIT_RequestAction($id, "LicenseDeactivate", "");'],
            ]];
        } elseif ($status['state'] === 'grace') {
            $actions[] = ['type' => 'Button', 'caption' => $this->t('Retry Now'), 'onClick' => 'BIT_RequestAction($id, "LicenseRefresh", "");'];
        } else {
            $actions[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'LicenseKey', 'caption' => $this->t('License Key'), 'width' => '350px'],
                ['type' => 'Button', 'caption' => $this->t('Activate'), 'onClick' => 'BIT_RequestAction($id, "LicenseActivate", $LicenseKey);'],
            ]];
        }

        return $actions;
    }
}
