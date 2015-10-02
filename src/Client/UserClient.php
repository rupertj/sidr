<?php

namespace SIDR\Client;

/**
 * Class UserClient
 * @package SIDR
 * A service for user operations. (Incomplete!)
 */

class UserClient {

  /** @var \SIDR\DrupalClient */
  protected $client;

  protected $cache;

  /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface */
  protected $session;

  public function __construct(DrupalClient $client, $cache, $session) {
    $this->client = $client;
    $this->cache = $cache;
    $this->session = $session;
  }

  /**
   * Returns TRUE/FALSE for whether the current user is logged in or not.
   * @return bool
   */
  public function isLoggedIn() {
    $user = $this->current();
    return !empty($user);
  }

  /**
   * Authenticates the user.
   * @param $username
   * @param $password
   * @return mixed
   */
  public function authenticate($username, $password) {
    $response = $this->client->post('user/login', [
      'body' => json_encode([
        'username' => $username,
        'password' => $password,
      ]),
    ]);

    return $response->json();
  }

  /**
   * Logs the user out of the Drupal backend and the front end.
   */
  public function logout() {
    try {
      $this->client->post('user/logout');
    }
    catch (\Exception $e) {
      // Swallow this exception. It'll only fail if the user is already logged out.
      // In that case we may as well log the user out of the front end too and pretend the whole thing worked.
    }

    // Clear out the session.
    $this->session->invalidate();
  }

  /**
   * Returns the currently logged in user. If no user is logged in, returns FALSE;
   */
  public function current() {
    return $this->session->get('user', FALSE);
  }

  /**
   * Loads a user.
   * @param $uid Integer
   */
  public function get($uid) {
    $response = $this->client->get('entity_user/' . $uid);
    return $response->json();
  }

  /**
   * Saves a user.
   * @param $user Array
   */
  public function save($user) {

    if (isset($user['uid'])) {
      $response = $this->client->put('entity_user/' . $user['uid'], array(
        'body' => json_encode($user),
      ));

      return $response->json();
    }
  }

  /**
   * Gets the URL for a password reset.
   * @param $name Username or Email
   * @return mixed
   */
  public function passwordReset($name) {
    $response = $this->client->post('entity_user/request_new_password', [
      'body' => json_encode([
        'name' => $name,
      ]),
    ]);

    return $response->json();
  }

  /**
   * Registers a new user.
   * @todo: Confirm differences between this and just saving a new user. Maybe combine into one call?
   * @param $user
   */
  public function register($user) {

    $response = $this->client->post('user/register', [
      'body' => json_encode([
        'account' => $user,
      ]),
    ]);

    return $response->json();
  }

  /**
   * Returns TRUE/FALSE for whether a user's token from a password reset email is valid.
   * @param $uid
   * @param $timestamp
   * @param $hash
   * @return bool
   */
  public function checkPasswordReset($uid, $timestamp, $hash) {

    $response = $this->client->post('user/confirm_new_password_token/' . $uid . '/' . $timestamp . '/' . $hash);
    return $response->json();
  }

  /**
   * @todo: Move more user related logic from UserController to this service class.
   */
}