<?php

class CraftyServer extends IPSModule
{
    private $craftyVersion = 'unknown'; // legacy | v4 | unknown

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('BaseURL', '');
        $this->RegisterPropertyString('APIToken', '');
        $this->RegisterPropertyString('ServerUUID', '');
        $this->RegisterPropertyInteger('ServerID', 0);

        $this->RegisterVariableString('Status', 'Status', '', 1);
        $this->RegisterVariableString('Stats', 'Stats', '', 2);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->craftyVersion = $this->DetectCraftyVersion($this->ReadPropertyString('BaseURL'));
    }

    /* ---------------------------------------------------------
     * VERSION DETECTION
     * --------------------------------------------------------- */

    private function DetectCraftyVersion(string $url): string
    {
        if (strpos($url, ':8111') !== false) {
            return 'legacy';
        }
        if (strpos($url, ':8443') !== false) {
            return 'v4';
        }

        return $this->ProbeCraftyAPI($url);
    }

    private function ProbeCraftyAPI(string $url): string
    {
        if ($this->TestEndpoint($url . '/api/v2/ping')) {
            return 'v4';
        }

        if ($this->TestEndpoint($url . '/panel/api/ping')) {
            return 'legacy';
        }

        return 'unknown';
    }

    private function TestEndpoint(string $url): bool
    {
        $result = @file_get_contents($url);
        return $result !== false;
    }

    /* ---------------------------------------------------------
     * PUBLIC API
     * --------------------------------------------------------- */

    public function StartServer()
    {
        return $this->CallAPI('start');
    }

    public function StopServer()
    {
        return $this->CallAPI('stop');
    }

    public function RestartServer()
    {
        return $this->CallAPI('restart');
    }

    public function UpdateStats()
    {
        $data = $this->CallAPI('stats');
        SetValueString($this->GetIDForIdent('Stats'), json_encode($data));
        return $data;
    }

    /* ---------------------------------------------------------
     * API ABSTRACTION
     * --------------------------------------------------------- */

    private function CallAPI(string $endpoint)
    {
        switch ($this->craftyVersion) {

            case 'legacy':
                $uuid = $this->ReadPropertyString('ServerUUID');
                return $this->LegacyRequest($uuid, $endpoint);

            case 'v4':
                $id = $this->ReadPropertyInteger('ServerID');
                return $this->V4Request($id, $endpoint);

            default:
                IPS_LogMessage('CraftyServer', 'Unknown Crafty version');
                return null;
        }
    }

    /* ---------------------------------------------------------
     * LEGACY API (UUID)
     * --------------------------------------------------------- */

    private function LegacyRequest(string $uuid, string $endpoint)
    {
        $url = $this->ReadPropertyString('BaseURL') . "/panel/api/server/$uuid/$endpoint";
        return $this->SendAPIRequest($url);
    }

    /* ---------------------------------------------------------
     * V4 API (numeric ID)
     * --------------------------------------------------------- */

    private function V4Request(int $id, string $endpoint)
    {
        $url = $this->ReadPropertyString('BaseURL') . "/api/v2/servers/$id/$endpoint";
        return $this->SendAPIRequest($url);
    }

    /* ---------------------------------------------------------
     * SHARED REQUEST HANDLER
     * --------------------------------------------------------- */

    private function SendAPIRequest(string $url)
    {
        $token = $this->ReadPropertyString('APIToken');

        $opts = [
            'http' => [
                'header' => "Authorization: Bearer $token\r\n"
            ]
        ];

        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            IPS_LogMessage('CraftyServer', "API request failed: $url");
            return null;
        }

        return json_decode($result, true);
    }
}
