<?php

namespace SIDR;

use GuzzleHttp;

/**
 * Class DrupalClient
 * @package SIDR
 *
 * @see DrupalServiceProvider for how to register this in a Silex app.
 */

class DrupalClient extends GuzzleHttp\Client {

  /**
   * @todo: Refactor this class to use composition over inheritance.
   * We don't actually need it to extend Guzzle's client, instead it'd be nice
   * to build a higher-level API here.
   */

  private $app;
  private $backend;
  private $endpoint;

  public function __construct($app, array $config = []) {
    $this->app = $app;
    parent::__construct($config);
  }

  public function setBackend($backend, $endpoint) {
    $this->backend = $backend;
    $this->endpoint = $endpoint;
  }

  public function cookies() {

    $cookies = array();

    if(!empty($this->app['xdebug_backend_requests'])) {
      $cookies['XDEBUG_SESSION'] = 'netbeans-xdebug';
    }

    $drupal_session = $this->app['session']->get('drupal');

    if ($drupal_session) {
      $cookies[$drupal_session['session_name']] = $drupal_session['sessid'];
    }

    return $cookies;
  }

  /**
   * Supplies the correct request headers for a request to Drupal.
   * @return array
   */
  public function headers() {

    $headers = array(
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
      // 'Accept-Encoding' => 'gzip', Slower.
    );

    # This is how new tokens are requested, but using the token from the initial log in works, so we don't need to do this.
    # $this->app['timer']->start('services/session/token');
    # $backend = parse_url($this->app['backend']);
    # $token_response = parent::get($backend['scheme'] . '://' . $backend['host'] . '/services/session/token', array('debug' => !empty($this->app['debug_backend_requests']);));
    # $headers['X-CSRF-Token'] = $token_response->getBody();
    # $this->app['timer']->end();

    $drupal_session = $this->app['session']->get('drupal');

    if ($drupal_session) {
      $headers['X-CSRF-Token'] = $drupal_session['token'];
    }

    return $headers;
  }

  /**
   * Like Drupal's drupal_http_build_query().
   * Use this for building query strings the easy way.
   * @param array $query
   * @param string $parent
   * @return string
   */
  public function query(array $query, $parent = '') {

      $params = array();

      foreach ($query as $key => $value) {
        $key = ($parent ? $parent . '[' . rawurlencode($key) . ']' : rawurlencode($key));

        // Recurse into children.
        if (is_array($value)) {
          $params [] = self::query($value, $key);
        }
        // If a query parameter value is NULL, only append its key.
        elseif (!isset($value)) {
          $params [] = $key;
        }
        else {
          // For better readability of paths in query strings, we decode slashes.
          $params [] = $key . '=' . str_replace('%2F', '/', rawurlencode($value));
        }
      }

      return implode('&', $params);
  }

  /**
   * Method for get requests.
   * @param null $url
   * @param array $options
   * @return mixed
   */

  public function get($url = null, $options = []) {
    return $this->request('get', $url, $options);
  }

  public function post($url = null, array $options = []) {
    return $this->request('post', $url, $options);
  }

  public function put($url = null, array $options = []) {
    return $this->request('put', $url, $options);
  }

  public function delete($url = null, array $options = []) {
    return $this->request('delete', $url, $options);
  }

  protected function request($method, $url = null, array $options = []) {

    if($cookies = $this->cookies()) {
      if (!isset($options['cookies'])) {
        $options['cookies'] = array();
      }
      $options['cookies'] = array_merge($cookies, $options['cookies']);
    }

    if($headers = $this->headers()) {
      if (!isset($options['headers'])) {
        $options['headers'] = array();
      }
      $options['headers'] = array_merge($headers, $options['headers']);
    }

    $options['debug'] = !empty($this->app['debug_backend_requests']);

    // Default this option to true.
    if (!isset($options['prefix_endpoint'])) {
      $options['prefix_endpoint'] = TRUE;
    }

    if ($options['prefix_endpoint']) {
      $backend = $this->backend . '/' . $this->endpoint . '/';
    }
    else {
      $backend = $this->backend . '/';
    }

    // Don't pass this to parent::$method. It's not a real guzzle option and
    // just confuses guzzle.
    unset($options['prefix_endpoint']);

    $this->app['timer']->start($url);
    $response = parent::$method($backend . $url, $options);
    $this->app['timer']->end();

    return $response;
  }

  public function userIsLoggedIn() {
    $user = $this->app['session']->get('user', FALSE);
    return !empty($user);
  }
}
