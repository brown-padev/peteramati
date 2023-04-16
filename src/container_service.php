<?php
// container_service_client.php -- Peteramati container service
// Peteramati is Copyright (c) 2013-2019 Eddie Kohler
// See LICENSE for open-source distribution terms

class SubmitJobRequest {
    public $psetName;
    public $testName;
    public $accessToken;
    public $repoOwner;
    public $repoName;
    public $commitID;
    public $studentID;

    function __construct($psetName, $testName, $accessToken, $repoOwner, $repoName, $commitID, $studentID) {
        $this->psetName = $psetName;
        $this->testName = $testName;
        $this->accessToken = $accessToken;
        $this->repoOwner = $repoOwner;
        $this->repoName = $repoName;
        $this->commitID = $commitID;
        $this->studentID = $studentID;
    }
}

class ContainerServiceClient implements JsonSerializabl {
    /** @var string */
    public $url = "http://localhost:8000/jobs/"; // https://cs1680.cs.brown.edu/pa-container-service/jobs/
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

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->response ?? ["status" => $this->status, "content" => $this->content];
    }

    private function debug($content) {
        $path = "/home/tdong6/debug.txt";
        // open with write and append
        $handle = fopen($path, "a");
        fwrite($handle, $content . "\n");
        fclose($handle);
    }

    private function run_post($content_type, $content, $header = "") {
        if (is_array($content) || is_object($content)) {
            if ($content_type === "application/x-www-form-urlencoded") {
                $content = (array) $content;
                $content = join("&", array_map(function ($k, $v) {
                    return urlencode($k) . "=" . urlencode($v);
                }, array_keys($content), array_values($content)));
            } else if ($content_type === "application/json") {
                $content = json_encode($content);
            } else {
                throw new Error();
            }
        }
        $header .= "Content-Type: $content_type\r\n";
        $htopt = [
            "method" => "POST",
            "header" => $header,
            "content" => $content
        ];
        $context = stream_context_create(array("http" => $htopt));
        if (($stream = fopen($this->url, "r", false, $context))) {
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
            $this->debug($this->content);
            if (
                $this->content !== false
                && ($j = json_decode(json_encode($this->content)))
                && is_object($j)
            ) {
                $this->debug("in");
                $this->debug($j);
                $this->response = $j;
                $this->debug($this->response);
                $rd = $j->data ?? null;
                if ($this->status === 200 && is_object($rd)) {
                    $this->rdata = $rd;
                }
            }
            fclose($stream);
        }
    }

    function submit_job(SubmitJobRequest $req) {
        $this->run_post("application/json", $req);
        // $this->debug($this->response);
        // $this->debug($this->response->data->jobID);
    }
}