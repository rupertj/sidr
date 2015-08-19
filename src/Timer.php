<?php
namespace SIDR;

/**
 * Class Timer
 * @package SIDR
 * Use this to time things like HTTP requests, etc.
 */
class Timer {

  private $times = array();
  private $start;
  private $description;

  public function start($description) {
    $this->start = microtime(TRUE);
    $this->description = $description;
  }

  public function end() {
    $this->times[] = array(
      'description' => $this->description,
      'start' => $this->start,
      'end' => microtime(TRUE),
    );
  }

  public function times() {

    $rtn = array();

    foreach ($this->times as $time) {
      $rtn[] = $time['description'] . ': ' . round($time['end'] - $time['start'], 3) . 's';
    }

    return $rtn;
  }
}