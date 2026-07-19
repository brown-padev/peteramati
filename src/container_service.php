<?php
// container_service_client.php -- Peteramati container service
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class JobRequest {
    /** @var string */
    public $psetConfigPath;
     /** @var string */
    public $jobID; 
     /** @var string */
    public $psetName;
     /** @var string */
    public $testName;
     /** @var string */
    public $accessToken;
     /** @var string */
    public $repoOwner;
     /** @var string */
    public $repoName;
     /** @var string */
    public $commitID;
     /** @var string */
    public $studentID;
     /** @var string */
    public $logFile;
     /** @var string */
    public $pidFile;
    /** @var string */
    public $inputFifo;
    /** @var ?array */
    public $runSettings;

    function __construct($psetConfigPath, $jobID, $psetName, $testName, $accessToken, $repoOwner, $repoName, $commitID, $studentID, $logFile, $pidFile, $inputFifo, $runSettings = null) {
        $this->psetConfigPath = $psetConfigPath;
        $this->jobID = strval($jobID);
        $this->psetName = $psetName;
        $this->testName = $testName;
        $this->accessToken = $accessToken;
        $this->repoOwner = $repoOwner;
        $this->repoName = $repoName;
        $this->commitID = $commitID;
        $this->studentID = $studentID;
        $this->logFile = $logFile;
        $this->pidFile = $pidFile;
        $this->inputFifo = $inputFifo;
        $this->runSettings = !empty($runSettings) ? (object) $runSettings : null;
    }
}

class ContainerServiceClient {
    /** @var int */
    public $status = 509;
    /** @var string */
    public $status_text;
    /** @var array<string,string> */
    public $headers = [];
    /** @var ?string */
    public $content;
    /** @var ?object */
    public $response;
    /** @var ?object */
    public $rdata;
    private static function request($endpoint, $method, $content = "") {
        global $Conf;
        $base = $Conf->opt("containerServiceUrl") ?? "http://localhost:8000";
        $url = rtrim($base, "/") . $endpoint;
        if ($content !== "") {
            $content = json_encode($content);
        }
        $htopt = [
            "method" => $method,
            "header" => "Content-Type: application/json",
            "content" => $content
        ];
        $context = stream_context_create(array("http" => $htopt));
        if (($stream = fopen($url, "r", false, $context))) {
            if (
                ($metadata = stream_get_meta_data($stream))
                && ($w = $metadata["wrapper_data"] ?? null)
                && is_array($w)
            ) {
                if (preg_match(',\AHTTP/[\d.]+\s+(\d+)\s+(.+)\z,', $w[0], $m)) {
                    $status = (int) $m[1];
                    $status_text = $m[2];
                }
                for ($i = 1; $i != count($w); ++$i) {
                    if (preg_match(',\A(.*?):\s*(.*)\z,', $w[$i], $m))
                        $headers[strtolower($m[1])] = $m[2];
                }
            }
            $content = stream_get_contents($stream);
            if (
                $content !== false
                && ($j = json_decode($content))
                && is_object($j)
            ) {
                $response = $j;
                $rd = $j->data ?? null;
                if ($status === 200 && is_object($rd)) {
                    $rdata = $rd;
                }
            }
            fclose($stream);
        }
    }

    static function submit_job(JobRequest $req) {
        self::request("/jobs", "POST", $req);
    }

    // job id is run at for queue item
    static function stop_job($jid) {
        self::request("/jobs/$jid", "DELETE");
    }

    /** Generate a signed WebSocket URL for a display connection.
     * @param string $jobID
     * @param int $userID
     * @return ?string */
    static function display_url($jobID, $userID) {
        global $Conf;
        $key = $Conf->opt("displayHmacKey");
        $agentUrl = $Conf->opt("agentExternalUrl");
        if (!$key || !$agentUrl) {
            return null;
        }
        $expires = time() + 3600; // 1 hour
        $message = $jobID . ":" . $userID . ":" . $expires;
        $token = hash_hmac("sha256", $message, $key);
        $base = rtrim($agentUrl, "/");
        return $base . "/display/" . urlencode($jobID)
            . "?token=" . urlencode($token)
            . "&user=" . urlencode($userID)
            . "&expires=" . $expires;
    }
}
