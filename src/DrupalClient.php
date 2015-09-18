<?php

namespace SIDR;

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

  /** @var  Application */
  protected $app;

  /** @var  String */
  protected $backend;

  /** @var  String */
  protected $endpoint;

  /** @var Array */
  protected $terms;

  /** @var GuzzleHttp\Client **/
  protected $client;

  public function __construct($app, array $config = []) {
    $this->app = $app;

    // @todo: Add support for a null cache or other caches. (PSR-6?)
    $this->cache = $app['memcache'];

    // @todo: DI.
    $this->client = new GuzzleHttp\Client($config);
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

    // Don't pass this to the client
    unset($options['prefix_endpoint']);

    $this->app['timer']->start($url . ' ' . serialize($options));
    $response = $this->client->$method($backend . $url, $options);
    $this->app['timer']->end();

    return $response;
  }

  public function userIsLoggedIn() {
    $user = $this->app['session']->get('user', FALSE);
    return !empty($user);
  }

  /**
   * Returns a flat list of terms for the requested vocabulary.
   * Response is cached if a cache is available.
   * @param $vocab_name Vocabulary machine name or Vocabulary ID.
   * @return mixed
   */
  public function getTermsForVocab($vocab_name) {

    // Check object first.
    if (isset($this->terms[$vocab_name])) {
      return $this->terms[$vocab_name];
    }

    // Then memcache.
    $cache_key = 'vocab/' . $vocab_name;
    $this->terms[$vocab_name] = $this->cache->get($cache_key);

    if ($this->terms[$vocab_name]) {
      return $this->terms[$vocab_name];
    }

    // Finally: Drupal.
    $result_raw = $this->post('taxonomy_vocabulary/getTree', [
      'body' => json_encode(array('vid' => $vocab_name)),
    ]);

    $result = $result_raw->json();

    // Build an array indexed by tid, as it's more useful:
    $terms = array();
    foreach ($result as $term) {
      $terms[$term['tid']] = $term;
    }

    $this->terms[$vocab_name] = $terms;

    // If we hit Drupal, set the result in memcache.
    $this->cache->set($cache_key, $this->terms[$vocab_name]);

    return $this->terms[$vocab_name];
  }

  /**
   * Returns a list of child terms of a specified parent.
   * Terms will be sorted by weight.
   * @param $vocab_name
   * @param int $parent
   * @return array
   */
  public function getTermsForVocabAtLevel($vocab_name, $parent = 0) {

    $terms = $this->getTermsForVocab($vocab_name);

    $terms_filtered = array();

    foreach ($terms as $term) {

      $this_parent = intval($term['parents'][0]);

      if ($parent == $this_parent) {
        $terms_filtered[] = $term;
      }
    }

    // Sort terms by weight.
    usort($terms_filtered, function ($a, $b) {
      if ($a['weight'] == $b['weight']) {
        return 0;
      }
      return ($a['weight'] > $b['weight']) ? 1 : -1;
    });

    return $terms_filtered;
  }

  /**
   * Loads a node by ID.
   * @param $nid ID of the node to load.
   * @param bool $cache Whether to use the cache. Defaults to true.
   */
  public function getNode($nid, $cache = TRUE) {

    if ($cache) {
      $cache_key = 'node/' . $nid;
      $cache_result = $this->cache->get($cache_key);

      if ($cache_result) {
        return $cache_result;
      }
    }

    $node_response = $this->get('entity_node/' . $nid);
    $node = $node_response->json();

    if ($cache) {
      $this->cache->set($cache_key, $node);
    }

    return $node;
  }

  /**
   * Saves or updates a node as appropriate.
   * @param $node
   */
  public function saveNode(&$node) {

    if (empty($node['nid'])) {
      // No nid, create one with a post.
      $response_raw = $this->post('node.json', [
        'body' => json_encode($node),
      ]);

      // Save the nid into the passed node so it can be used.
      $response = $response_raw->json();
      $node['nid'] = $response['nid'];
    }
    else {
      // Nid is set, update with a put.
      $this->put('entity_node/' . $node['nid'], array(
        'body' => json_encode($node),
      ));
    }
  }
}
