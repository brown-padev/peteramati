<?php
// gradeexport.php -- Peteramati class for JSON-compatible grade entry export
// HotCRP and Peteramati are Copyright (c) 2006-2019 Eddie Kohler and others
// See LICENSE for open-source distribution terms

class GradeExport implements JsonSerializable {
    /** @var Pset */
    public $pset;
    /** @var bool */
    public $pc_view;
    /** @var bool */
    public $include_entries = true;
    /** @var ?int */
    public $uid;
    /** @var ?list<float> */
    public $grades;
    /** @var ?list<float> */
    public $autogrades;
    /** @var null|int|float */
    public $total;
    /** @var null|int|float */
    public $total_noextra;
    /** @var ?string */
    public $grading_hash;
    /** @var null|int */
    public $late_hours;
    /** @var null|int */
    public $auto_late_hours;
    /** @var ?bool */
    public $editable;
    /** @var ?list<GradeEntryConfig> */
    private $visible_grades;

    /** @param bool $pc_view */
    function __construct(Pset $pset, $pc_view) {
        $this->pset = $pset;
        $this->pc_view = $pc_view;
    }

    /** @return list<GradeEntryConfig> */
    function visible_grades() {
        if ($this->visible_grades !== null) {
            return $this->visible_grades;
        } else {
            return $this->pset->visible_grades($this->pc_view);
        }
    }

    /** @param list<GradeEntryConfig> $vg */
    function set_visible_grades($vges) {
        $this->visible_grades = $vges;
    }

    function suppress_absent_extra() {
        $ges = $this->visible_grades();
        $nges = count($ges);
        for ($i = 0; $i !== count($ges); ) {
            if ($ges[$i]->is_extra
                && ($this->grades[$i] ?? 0) == 0) {
                array_splice($ges, $i, 1);
                array_splice($this->grades, $i, 1);
                if ($this->autogrades !== null) {
                    array_splice($this->autogrades, $i, 1);
                }
            } else {
                ++$i;
            }
        }
        if ($i !== $nges) {
            $this->visible_grades = $ges;
        }
    }

    /** @return array */
    function jsonSerialize() {
        $r = [];
        if (isset($this->uid)) {
            $r["uid"] = $this->uid;
            if ($this->grades !== null) {
                $r["grades"] = $this->grades;
            }
            if ($this->pc_view && !empty($this->autogrades)) {
                $r["autogrades"] = $this->autogrades;
            }
            if ($this->total !== null) {
                $r["total"] = $this->total;
            }
            if ($this->total_noextra !== null) {
                $r["total_noextra"] = $this->total_noextra;
            }
            if ($this->grading_hash !== null) {
                $r["grading_hash"] = $this->grading_hash;
            }
            if ($this->late_hours !== null) {
                $r["late_hours"] = $this->late_hours;
            }
            if ($this->auto_late_hours !== null) {
                $r["auto_late_hours"] = $this->auto_late_hours;
            }
            if ($this->editable !== null) {
                $r["editable"] = $this->editable;
            }
        }
        if ($this->include_entries) {
            $entries = $order = [];
            $gi = $maxtotal = 0;
            foreach ($this->visible_grades() as $ge) {
                $order[] = $ge->key;
                $entries[$ge->key] = $ge->json($this->pc_view, $gi);
                if ($ge->max
                    && !$ge->is_extra
                    && !$ge->no_total
                    && ($this->pc_view || $ge->max_visible)) {
                    $maxtotal += $ge->max;
                }
                ++$gi;
            }
            $r["entries"] = $entries;
            $r["order"] = $order;
            if ($this->pset->grades_total !== null) {
                $r["maxtotal"] = $this->pset->grades_total;
            } else if ($maxtotal > 0) {
                $r["maxtotal"] = $maxtotal;
            }
        }
        return $r;
    }
}
