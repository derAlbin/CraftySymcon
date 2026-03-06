<?php

class CraftyServer extends IPSModule
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

        // Status / Stats
        $this->RegisterVariableString("ServerName", "Servername");
        $this->RegisterVariableBoolean("Online", "Online", "~Switch");
        $this->EnableAction("Online");

        $this->RegisterVariableInteger("Players", "Spieler online");
        $this->RegisterVariableFloat("CPU", "CPU (%)");
        $this->RegisterVariableFloat("RAM", "RAM (MB)");

        // Aktionen / Parameter
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

        $interval = $this->ReadPropertyInteger("Interval");
        $this->SetTimerInterval("UpdateTimer", $interval * 1000);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "Online":
                if ($Value) {
                    $this->StartServer();
                } else {
                    $this->StopServer();
                }
                break;

            case "PlayerName":
                SetValue($this->GetIDForIdent("PlayerName"), $Value);
                break;

            case "BanReason":
                SetValue($this->GetIDForIdent("BanReason"), $Value);
                break;

            case "ConsoleCommand":
                SetValue($this->GetIDForIdent("ConsoleCommand"), $Value);
                $this->SendCommand($Value);
                break;

            case "MaxPlayers":
                SetValue($this->GetIDForIdent("MaxPlayers"), $Value);
                $this->SetServerSetting("max_players", $Value);
                break;

            case "MOTD":
                SetValue($this->GetIDForIdent("MOTD"), $Value);
                $this->SetServerSetting("motd", $Value);
                break;
        }
    }

    public function Update()
    {
        $serverID = $this->ReadPropertyInteger("ServerID");

        $servers = $this->CallAPI("GET", "/api/v2/servers");
        if ($servers === false) {
            return;
        }

        foreach ($servers as $server) {
            if ($server["id"] == $serverID) {
                @SetValue($this->GetIDForIdent("ServerName"), $server["name"]);
                @SetValue($this->GetIDForIdent("Online"), $server["running"]);
                if (isset($server["stats"]["players"])) {
                    @SetValue($this->GetIDForIdent("Players"), $server["stats"]["players"]);
                }
                if (isset($server["stats"]["cpu"])) {
                    @SetValue($this->GetIDForIdent("CPU"), $server["stats"]["cpu"]);
                }
                if (isset($server["stats"]["memory"])) {
                    @SetValue($this->GetIDForIdent("RAM"), $server["stats"]["memory"]);
                }
                return;
            }
        }

        IPS_LogMessage("Crafty", "Server-ID nicht gefunden: $serverID");
    }

    // ---- Standard-Aktionen ----

    public function StartServer()
    {
        $id = $this->ReadPropertyInteger("ServerID");
        $this->CallAPI("POST", "/api/v2/servers/{$id}/start");
        $this->Update();
    }

    public function StopServer()
    {
        $id = $this->ReadPropertyInteger("ServerID");
        $this->CallAPI("POST", "/api/v2/servers/{$id}/stop");
        $this->Update();
    }

    public function RestartServer()
    {
        $id = $this->ReadPropertyInteger("ServerID");
        $this->CallAPI("POST", "/api/v2/servers/{$id}/restart");
        $this->Update();
    }

    public function KickPlayer(string $Player = "")
    {
        $id = $this->ReadPropertyInteger("ServerID");
        if ($Player == "") {
            $Player = GetValueString($this->GetIDForIdent("PlayerName"));
        }
        if ($Player == "") {
            IPS_LogMessage("Crafty", "KickPlayer: Kein Spielername gesetzt");
            return;
        }

        $payload = ["player" => $Player];
        $this->CallAPI("POST", "/api/v2/servers/{$id}/players/kick", $payload);
    }

    public function BanPlayer(string $Player = "", string $Reason = "")
    {
        $id = $this->ReadPropertyInteger("ServerID");
        if ($Player == "") {
            $Player = GetValueString($this->GetIDForIdent("PlayerName"));
        }
        if ($Reason == "") {
            $Reason = GetValueString($this->GetIDForIdent("BanReason"));
        }
        if ($Player == "") {
            IPS_LogMessage("Crafty", "BanPlayer: Kein Spielername gesetzt");
            return;
        }

        $payload = [
            "player" => $Player,
            "reason" => $Reason
        ];
        $this->CallAPI("POST", "/api/v2/servers/{$id}/players/ban", $payload);
    }

    public function UnbanPlayer(string $Player = "")
    {
        $id = $this->ReadPropertyInteger("ServerID");
        if ($Player == "") {
            $Player = GetValueString($this->GetIDForIdent("PlayerName"));
        }
        if ($Player == "") {
            IPS_LogMessage("Crafty", "UnbanPlayer: Kein Spielername gesetzt");
            return;
        }

        $payload = ["player" => $Player];
        $this->CallAPI("POST", "/api/v2/servers/{$id}/players/unban", $payload);
    }

    public function SendCommand(string $Command = "")
    {
        $id = $this->ReadPropertyInteger("ServerID");
        if ($Command == "") {
            $Command = GetValueString($this->GetIDForIdent("ConsoleCommand"));
        }
        if ($Command == "") {
            IPS_LogMessage("Crafty", "SendCommand: Kein Befehl gesetzt");
            return;
        }

        $payload = ["command" => $Command];
        $this->CallAPI("POST", "/api/v2/servers/{$id}/console", $payload);
    }

    // ---- Generische Schreib-Funktion für Settings ----

    public function SetServerSetting(string $Key, $Value)
    {
        $id = $this->ReadPropertyInteger("ServerID");

        $payload = [
            $Key => $Value
        ];

        // Pfad ggf. an Crafty-4-Doku anpassen
        $this->CallAPI("PATCH", "/api/v2/servers/{$id}/settings", $payload);
    }

    // ---- Zentrale API-Funktion ----

    private function CallAPI(string $Method, string $Path, array $Payload = null)
    {
        $host  = $this->ReadPropertyString("Host");
        $port  = $this->ReadPropertyInteger("Port");
        $token = $this->ReadPropertyString("Token");

        if ($host == "" || $token == "") {
            IPS_LogMessage("Crafty", "Host oder Token fehlt.");
            return false;
        }

        $url = "http://{$host}:{$port}{$Path}";

        $headers = "Authorization: Bearer {$token}\r\n";
        $options = [
            "http" => [
                "method" => $Method,
                "header" => $headers
            ]
        ];

        if ($Payload !== null) {
            $body = json_encode($Payload);
            $options["http"]["header"] .= "Content-Type: application/json\r\n";
            $options["http"]["content"] = $body;
        }

        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            IPS_LogMessage("Crafty", "API-Fehler bei {$Method} {$url}");
            return false;
        }

        $data = json_decode($response, true);
        return $data === null ? $response : $data;
    }
}
