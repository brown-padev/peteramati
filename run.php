<?php
// run.php -- Peteramati runner page
// HotCRP and Peteramati are Copyright (c) 2006-2015 Eddie Kohler and others
// See LICENSE for open-source distribution terms

require_once("src/initweb.php");
if ($Me->is_empty())
    $Me->escape();
global $User, $Pset, $Psetid, $Info, $RecentCommits, $Qreq;

function quit($err = null) {
    global $Conf;
    $Conf->ajaxExit(array("ok" => false, "error" => $err));
}

function user_pset_info() {
    global $Conf, $User, $Pset, $Info, $Qreq;
    $Info = ContactView::user_pset_info($User, $Pset);
    if (($Commit = $Qreq->newcommit) == null)
        $Commit = $Qreq->commit;
    if (!$Info->set_hash($Commit))
        $Conf->ajaxExit(array("ok" => false, "error" => $Info->repo ? "No repository." : "Commit " . htmlspecialchars($Commit) . " isn’t connected to this repository."));
    return $Info;
}

ContactView::set_path_request(array("/@", "/@/p", "/@/p/h", "/p", "/p/h", "/p/u/h"));
$Qreq = make_qreq();

// user, pset, runner
$User = $Me;
if ($Qreq->u !== null
    && !($User = ContactView::prepare_user($Qreq->u)))
    $Conf->ajaxExit(["ok" => false]);
assert($User == $Me || $Me->isPC);

$Pset = ContactView::find_pset_redirect($Qreq->pset);
$Psetid = $Pset->id;

// XXX this should be under `api`
if ($Qreq->flag && check_post($Qreq) && user_pset_info()) {
    $flags = (array) $Info->current_info("flags");
    if ($Qreq->flagid && !isset($flags["t" . $Qreq->flagid]))
        json_exit(["ok" => false, "error" => "No such flag"]);
    if (!$Qreq->flagid)
        $Qreq->flagid = "t" . $Now;
    $flag = get($flags, $Qreq->flagid, []);
    if (!get($flag, "uid"))
        $flag["uid"] = $Me->contactId;
    if (!get($flag, "started"))
        $flag["started"] = $Now;
    if (get($flag, "started", $Qreq->flagid) != $Now)
        $flag["updated"] = $Now;
    if ($Qreq->flagreason)
        $flag["conversation"][] = [$Now, $Me->contactId, $Qreq->flagreason];
    $updates = ["flags" => [$Qreq->flagid => $flag]];
    $Info->update_current_info($updates);
    json_exit(["ok" => true]);
}

// XXX this should be under `api`
if ($Qreq->resolveflag && check_post($Qreq) && user_pset_info()) {
    $flags = (array) $Info->current_info("flags");
    if (!$Qreq->flagid || !isset($flags[$Qreq->flagid]))
        json_exit(["ok" => false, "error" => "No such flag"]);
    if (get($flags[$Qreq->flagid], "resolved"))
        json_exit(["ok" => true]);
    $updates = ["flags" => [$Qreq->flagid => ["resolved" => [$Now, $Me->contactId]]]];
    $Info->update_current_info($updates);
    json_exit(["ok" => true]);
}

if (!$Pset->run_dirpattern)
    quit("Configuration error (run_dirpattern)");
else if (!$Pset->run_jailfiles)
    quit("Configuration error (run_jailfiles)");
$Runner = null;
foreach ($Pset->runners as $r)
    if ($r->name == $Qreq->run)
        $Runner = $r;
$RunMany = $Me->isPC && get($_GET, "runmany") && check_post();
if (!$Runner)
    quit("No such command");
else if (!$Me->can_view_run($Pset, $Runner, $RunMany ? null : $User)) {
    if (!$Me->isPC && !$Runner->visible)
        quit("Command reserved for TFs");
    else if ($Runner->disabled)
        quit("Command disabled");
    else
        quit("Can’t run command right now");
}

// magic multi-runner
if ($Me->isPC && get($_GET, "runmany") && check_post()) {
    $Conf->header(htmlspecialchars($Pset->title . " " . $Runner->title), "home");

    echo '<h2 id="runmany61_who"></h2>',
        Ht::form(hoturl_post("run")),
        '<div class="f-contain">',
        Ht::hidden("u", ""),
        Ht::hidden("pset", $Pset->urlkey),
        Ht::hidden("run", $Runner->name, ["id" => "runmany61", "data-pa-runclass" => $Runner->runclass_argument()]),
        '</div></form>';

    echo '<div id="run61out_' . $Runner->runclass . '">',
        '<div class="run61" id="run61_' . $Runner->runclass . '">',
        '<div class="run61in"><pre class="run61pre"></pre></div>',
        '</div>',
        '</div>';

    echo '<div id="runmany61_users">',
        htmlspecialchars($_GET["runmany"]),
        '</div>';

    Ht::stash_script('runmany61()');
    echo '<div class="clear"></div>', "\n";
    $Conf->footer();
    exit;
}

// repo
$Info = user_pset_info();
$Repo = $Info->repo;
$RecentCommits = $Info->recent_commits();

// can we run this?
if (!$Repo)
    quit("No repository to run");
else if (!$Info->commit())
    quit("No commit to run");
else if ($Qreq->run === null || !check_post())
    quit("Permission error");
else if (!$Info->can_view_repo_contents)
    quit("Unconfirmed repository");


// extract request info
$Queueid = cvtint($Qreq->get("queueid", -1));
$checkt = cvtint($Qreq->get("check"));
$Offset = cvtint($Qreq->get("offset", -1));


// maybe eval
function runner_eval($runner, $info, $answer) {
    global $ConfSitePATH;
    if (isset($runner->load) && $runner->load[0] == "/")
        require_once($runner->load);
    else if (isset($runner->load))
        require_once($ConfSitePATH . "/" . $runner->load);
    $answer->result = call_user_func($runner->eval, $info);
}


// checkup
if ($checkt > 0
    && $answer = ContactView::runner_json($Info, $checkt, $Offset)) {
    if ($answer->status == "working" && get($_POST, "stop")) {
        ContactView::runner_write($Info, $checkt, "\x1b\x03");
        $now = microtime(true);
        do {
            $answer = ContactView::runner_json($Info, $checkt, $Offset);
        } while ($answer->status == "working" && microtime(true) - $now < 0.1);
    }
    if ($answer->status != "working" && $Queueid > 0)
        $Conf->qe("delete from ExecutionQueue where queueid=? and repoid=?", $Queueid, $Info->repo->repoid);
    if ($answer->status == "done"
        && $Me->can_run($Pset, $Runner, $User)
        && $Runner->eval)
        runner_eval($Runner, $Info, $answer);
    $Conf->ajaxExit($answer);
}


// if not checkup, then we’re gonna run it; check permission
if (!$Me->can_run($Pset, $Runner, $User))
    quit("You can’t run that command");


// queue
function load_queue($queueid, $repo) {
    global $Conf, $Now;
    $result = $Conf->qe("select q.*,
            count(fq.queueid) nahead,
            min(if(fq.runat>0,fq.runat,$Now)) as head_runat,
            min(fq.nconcurrent) as ahead_nconcurrent
        from ExecutionQueue q
        left join ExecutionQueue fq on (fq.queueclass=q.queueclass and fq.queueid<q.queueid)
        where q.queueid=$queueid group by q.queueid");
    if (!($queue = edb_orow($result)))
        quit("Queued job was cancelled, try again");
    else if ($queue->repoid != $repo->repoid)
        quit("Queued job belongs to a different repository");
    return $queue;
}

function clean_queue($qname, $qconf, $qid) {
    global $Conf, $Now;
    $runtimeout = isset($qconf->runtimeout) ? $qconf->runtimeout : 300;
    $result = $Conf->qe("select * from ExecutionQueue where queueclass=? and queueid<?", $qname, $qid);
    while (($row = edb_orow($result))) {
        // remove dead items from queue
        // - lockfile contains "0\n": child has exited, remove it
        // - lockfile specified but not there
        // - no lockfile & last update < 30sec ago
        // - running for more than 5min (configurable)
        if ($row->lockfile && @file_get_contents($row->lockfile) === "0\n") {
            unlink($row->lockfile);
            $row->inputfifo && unlink($row->inputfifo);
        }
        if (($row->lockfile && !file_exists($row->lockfile))
            || ($row->runat <= 0 && $row->updateat < $Now - 30)
            || ($runtimeout && $row->runat > 0 && $row->runat < $Now - $runtimeout))
            $Conf->qe("delete from ExecutionQueue where queueid=?", $row->queueid);
    }
}

$Queue = null;
if (isset($Runner->queue)) {
    if ($Queueid < 0 && $checkt > 0)
        quit("No such job");
    if ($Queueid < 0) {
        $Conf->qe("insert into ExecutionQueue set queueclass=?, insertat=?, updateat=?, repoid=?, runat=0, status=0, psetid=?, hash=?, nconcurrent=?",
                  $Runner->queue, $Now, $Now, $Info->repo->repoid,
                  $Pset->id, $Info->commit_hash(),
                  isset($Runner->nconcurrent) && $Runner->nconcurrent ? $Runner->nconcurrent : null);
        $Queueid = $Conf->dblink->insert_id;
    } else
        $Conf->qe("update ExecutionQueue set updateat=? where queueid=?", $Now, $Queueid);
    $Queue = load_queue($Queueid, $Info->repo);

    $qconf = defval(defval($PsetInfo, "_queues", array()), $Runner->queue);
    if (!$qconf)
        $qconf = (object) array("nconcurrent" => 1);

    $nconcurrent = defval($qconf, "nconcurrent", 1000);
    if ($Runner->nconcurrent > 0 && $Runner->nconcurrent < $nconcurrent)
        $nconcurrent = $Runner->nconcurrent;
    if (get($Queue, "ahead_nconcurrent") > 0 && $Queue->ahead_nconcurrent < $nconcurrent)
        $nconcurrent = $Queue->ahead_nconcurrent;

    for ($tries = 0; $tries < 2; ++$tries) {
        // error_log($User->seascode_username . ": $Queue->queueid, $nconcurrent, $Queue->nahead, $Queue->ahead_nconcurrent");
        if ($nconcurrent > 0 && $Queue->nahead >= $nconcurrent) {
            if ($tries)
                $Conf->ajaxExit(array("onqueue" => true, "queueid" => $Queue->queueid, "nahead" => $Queue->nahead, "headage" => ($Queue->head_runat ? $Now - $Queue->head_runat : null)));
            clean_queue($Runner->queue, $qconf, $Queue->queueid);
            $Queue = load_queue($Queueid, $Info->repo);
        } else
            break;
    }

    // if we get here we can actually run
}


$checkt = time();


// maybe eval
if (!$Runner->command && $Runner->eval) {
    $json = ContactView::runner_generic_json($Info, $checkt);
    $json->done = true;
    $json->status = "done";
    runner_eval($Runner, $Info, $json);
    $Conf->ajaxExit($json);
}


// otherwise run
try {
    $rs = new RunnerState($Info, $Runner, $Queue);

    // recent
    if ($Qreq->check == "recent" && count($rs->logged_checkts))
        $Conf->ajaxExit(ContactView::runner_json($Info, $rs->logged_checkts[0], $Offset));
    else if ($Qreq->check == "recent")
        quit("no logs yet");

    if ($rs->is_recent_job_running())
        quit("recent job still running");

    // run
    $rs->start();

    // save information about execution
    $Info->update_commit_info(["run" => [$Runner->runclass => $rs->checkt]]);

    $Conf->ajaxExit(array("ok" => true,
                          "done" => false,
                          "status" => "working",
                          "repoid" => $Info->repo->repoid,
                          "pset" => $Info->pset->id,
                          "timestamp" => $rs->checkt));
} catch (Exception $e) {
    quit($e->getMessage());
}
