<?php
// container_service_client.php -- Peteramati container service
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class JobRequest {
    public $psetName;
    public $testName;
    public $accessToken;
    public $repoOwner;
    public $repoName;
    public $commitID;
    public $studentID;
    public $logFile;
    public $pidFile;

    function __construct($psetName, $testName, $accessToken, $repoOwner, $repoName, $commitID, $studentID, $logFile, $pidFile) {
        $this->psetName = $psetName;
        $this->testName = $testName;
        $this->accessToken = $accessToken;
        $this->repoOwner = $repoOwner;
        $this->repoName = $repoName;
        $this->commitID = $commitID;
        $this->studentID = $studentID;
        $this->logFile = $logFile;
        $this->pidFile = $pidFile;
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
    private $baseHost = "http://localhost:8000"; // https://cs1680.cs.brown.edu/pa-container-service
    private $jobReq;
    private $jobId;

    function __construct(JobRequest $req) {
        $this->jobReq = $req;
    }

    private function debug($content) {
        $path = "/home/tdong6/debug.txt";
        $handle = fopen($path, "a");
        fwrite($handle, $content . "\n");
        fclose($handle);
    }

    private function request($endpoint, $method, $content = "") {
        $url = $this->baseHost . $endpoint;
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
                    $this->status = (int) $m[1];
                    $this->status_text = $m[2];
                }
                for ($i = 1; $i != count($w); ++$i) {
                    if (preg_match(',\A(.*?):\s*(.*)\z,', $w[$i], $m))
                        $this->headers[strtolower($m[1])] = $m[2];
                }
            }
            $this->content = stream_get_contents($stream);
            if (
                $this->content !== false
                && ($j = json_decode($this->content))
                && is_object($j)
            ) {
                $this->response = $j;
                $rd = $j->data ?? null;
                if ($this->status === 200 && is_object($rd)) {
                    $this->rdata = $rd;
                }
            }
            fclose($stream);
        }
    }

    function submit_job(): bool {
        $this->request("/jobs", "POST", $this->jobReq);
        if ($this->response->jobID) {
            $this->jobId = $this->response->jobID;
            return true;
        }
        return false;
    }

    function check_status(): string {
        $this->request("/jobs/" . $this->jobId, "GET");
        return $this->response->status;
    }

    function wait_for_completion() {
        // block until check_status() returns "completed"
        while (1) {
            // TODO: exponential backoff
            sleep(5);
            if ($this->check_status() === "success" || $this->check_status() === "failed") {
                return $this->response;
            }
        }
    }
}