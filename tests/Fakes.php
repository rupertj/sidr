<?php

/**
 * Class FakeSession.
 * Just stores key/value in an array.
 */
class FakeSession {

  protected $data = array();

  public function set($name, $value) {
    $this->data[$name] = $value;
  }

  public function get($name, $default = null) {

    if (isset($this->data[$name])) {
      return $this->data[$name];
    }

    return $default;
  }
}

/**
 * Class FakeTimer
 * Doesn't do anything at all.
 */
class FakeTimer {
  public function start() { }
  public function end() { }
}