<?php

class CraftySymcon extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("Host", "");
        $this->RegisterPropertyInteger("Port", 8000);
        $this->RegisterPropertyString("Token", "");
        $this->RegisterPropertyInteger("ServerID", 1);
        $this->RegisterPropertyInteger("Interval", 30);

        $this->RegisterTimer("UpdateTimer", 0, "CRAFTY_Update(\$_IPS['TARGET']);");

        $this->RegisterVariableString("ServerName", "Servername");
        $this->RegisterVariableBoolean("Online", "Online", "~Switch");
        $this->EnableAction("Online");

        $this->RegisterVariableInteger("Players", "Spieler online");
        $this->RegisterVariableFloat("CPU", "CPU (%)");
        $this->RegisterVariableFloat("RAM", "RAM (MB)");

        $this->RegisterVariableString("PlayerName", "Spielername");
        $this->EnableAction("PlayerName");

        $this->RegisterVariableString("BanReason", "Ban-Grund");
        $this->EnableAction("BanReason");

        $this->RegisterVariableString("ConsoleCommand", "Konsolenbefehl");
        $this->EnableAction("ConsoleCommand");

        $this->RegisterVariableInteger("MaxPlayers", "Max. Spieler");
        $this->EnableAction("MaxPlayers");

        $this->RegisterVariableString("MOTD", "MOTD");
        $this->EnableAction("MOTD");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval("UpdateTimer", $this->ReadPropertyInteger("Interval") * 1000);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "Online":
                $Value ? $this->StartServer() : $this->StopServer();
                break;

            case "PlayerName":
            case "BanReason":
            case "ConsoleCommand":
            case "MaxPlayers":
            case "MOTD":
                SetValue($this->GetIDForIdent($Ident), $Value);
                break;
        }
    }

    public function Update()
    {
        $serverID = $this->ReadPropertyInteger("ServerID");
        $servers = $this->CallAPI("GET", "/api/v2/servers");

        if (!is_array($servers)) return;

        foreach ($servers as $server) {
            if ($server["id"] == $serverID) {
                SetValue($this->GetIDForIdent("ServerName"), $server["name"]);
                SetValue($this->GetIDForIdent("Online"), $server["running"]);
                SetValue($this->GetIDForIdent("Players"), $server["stats"]["players"] ?? 0);
                SetValue($this->GetIDForIdent("CPU"), $server["stats"]["cpu"] ?? 0);
                SetValue($this->GetIDForIdent("RAM"), $server["stats"]["memory"] ?? 0);
                return;
            }
        }
    }

    public function StartServer() { $this->CallAPI("POST", "/api/v2/servers/".$this->ReadPropertyInteger("ServerID")."/start"); }
    public function StopServer() { $this->CallAPI("POST", "/api/v2/servers/".$this->ReadPropertyInteger("ServerID")."/stop"); }
    public function RestartServer() { $this->CallAPI("POST", "/api/v2/servers/".$this->ReadPropertyInteger("ServerID")."/restart"); }

    public function KickPlayer() {
        $this->CallAPI("POST", "/api/v2/servers/".$this->ReadPropertyInteger("ServerID")."/players/kick", [
            "player" => GetValueString($this->GetIDForIdent("PlayerName"))
        ]);
    }

    public function BanPlayer() {
        $this->CallAPI("POST", "/api/v2/servers/".$this->ReadPropertyInteger("ServerID")."/players/ban", [
            "player" => GetValueString($this->GetIDForIdent("PlayerName")),
            "reason" => GetValueString($this->GetIDForIdent("BanReason"))
        ]);
    }

    public function UnbanPlayer() {
        $this->CallAPI("POST", "/api/v2/servers/".$this->ReadPropertyInteger("ServerID")."/players/unban", [
            "player" => GetValueString($this->GetIDForIdent("PlayerName"))
        ]);
    }

    public function SendCommand() {
        $this->CallAPI("POST", "/api/v2/servers/".$this->ReadPropertyInteger("ServerID")."/console", [
            "command" => GetValueString($this->GetIDForIdent("ConsoleCommand"))
        ]);
    }

    private function CallAPI(string $Method, string $Path, array $Payload = null)
    {
        $url = "http://".$this->ReadPropertyString("Host").":".$this->ReadPropertyInteger("Port").$Path;
        $token = $this->ReadPropertyString("Token");

        $opts = [
            "http" => [
                "method" => $Method,
                "header" => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
                "content" => $Payload ? json_encode($Payload) : ""
            ]
        ];

        $response = @file_get_contents($url, false, stream_context_create($opts));
        return json_decode($response, true);
    }
}
