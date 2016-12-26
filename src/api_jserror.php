<?php

class API_JSError {
    static function jserror(Contact $user, Qrequest $qreq, APIData $api) {
        $url = $qreq->url;
        if (preg_match(',[/=]((?:script|jquery)[^/&;]*[.]js),', $url, $m))
            $url = $m[1];
        if (($n = $qreq->lineno))
            $url .= ":" . $n;
        if (($n = $qreq->colno))
            $url .= ":" . $n;
        if ($url !== "")
            $url .= ": ";
        $errormsg = trim((string) $qreq->error);
        if ($errormsg) {
            $suffix = "";
            if ($user->email)
                $suffix .= ", user " . $user->email;
            if (isset($_SERVER["REMOTE_ADDR"]))
                $suffix .= ", host " . $_SERVER["REMOTE_ADDR"];
            error_log("JS error: $url$errormsg$suffix");
            if (($stacktext = $qreq->stack)) {
                $stack = array();
                foreach (explode("\n", $stacktext) as $line) {
                    $line = trim($line);
                    if ($line === "" || $line === $errormsg || "Uncaught $line" === $errormsg)
                        continue;
                    if (preg_match('/\Aat (\S+) \((\S+)\)/', $line, $m))
                        $line = $m[1] . "@" . $m[2];
                    else if (substr($line, 0, 1) === "@")
                        $line = substr($line, 1);
                    else if (substr($line, 0, 3) === "at ")
                        $line = substr($line, 3);
                    $stack[] = $line;
                }
                error_log("JS error: {$url}via " . join(" ", $stack));
            }
        }
        json_exit(["ok" => true]);
    }
}
