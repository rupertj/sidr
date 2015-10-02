<?php

namespace SIDR\Client;

use Silex\Application;
use GuzzleHttp;

/**
 * Class DrupalClient
 * @package SIDR
 *
 * To use this in a project, register it like this:
 *
 *
 * $app->register(new SIDR\DrupalServiceProvider(), [
 *   'drupal.backend' => 'http://example.com',
 *   'drupal.endpoint' => 'services',
 * ]);
 *
 */

class DrupalClient {

  /** @var  String */
  protected $backend;

  /** @var  String */
  protected $endpoint;

  /** @var GuzzleHttp\Client **/
  protected $client;

  /** @var Timer */
  protected $timer;

  public function __construct($app, $guzzle_client, array $config = []) {
    $this->client = $guzzle_client;
    $this->timer = $app['timer'];
    $this->add_xdebug_cookie = !empty($app['xdebug_backend_requests']);
    $this->guzzle_debug = !empty($app['debug_backend_requests']);

    // @todo: Session can probably be removed once all the user stuff is moved to UserClient?
    $this->session = $app['session'];
  }

  public function setBackend($backend, $endpoint) {
    $this->backend = $backend;
    $this->endpoint = $endpoint;
  }

  public function cookies() {

    $cookies = array();

    if($this->add_xdebug_cookie) {
      $cookies['XDEBUG_SESSION'] = 'netbeans-xdebug';
    }

    $drupal_session = $this->session->get('drupal');

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

    $drupal_session = $this->session->get('drupal');

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

    $options['debug'] = $this->guzzle_debug;

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

    // Don't pass this to the client
    unset($options['prefix_endpoint']);

    $this->timer->start($url . ' ' . serialize($options));
    $response = $this->client->$method($backend . $url, $options);
    $this->timer->end();

    return $response;
  }
}
