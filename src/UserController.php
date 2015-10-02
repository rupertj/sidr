<?php

namespace SIDR;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp;

class UserController {

  protected $app;

  /** @var Array Default paths to redirect to after named events. */
  protected $redirects;

  public function __construct() {
    $this->redirects = array(
      'logout' => '/',
      'register.success' => '/',
      'already.registered' => '/user',
    );

    $this->messages = array(
      'login.success' => 'You have been logged in',
      'login.fail' => 'Log in failed. Please try again.',
      'logout.success' => 'You have been logged out.',
      'account.denied' => 'Please log in to view your account.',
      'account.update.success' => 'Your account has been updated.',
    );
  }

  /**
   * Logs a user in.
   * @param Request $request
   * @param Application $app
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */

  public function login(Request $request, Application $app) {

    $this->app = $app;

    $destination = trim($request->query->get('destination', ''), '/');

    if ($app['drupal']->userIsLoggedIn()) {
      if (!$destination) {
        return $app->redirect('/');
      }
      else {
        return $app->redirect($destination);
      }
    }

    return $app['twig']->render('page-login.twig', array(
      'destination' => $destination,
    ));
  }

  public function authenticate(Request $request, Application $app) {

    $this->app = $app;

    if ($app['drupal']->userIsLoggedIn()) {
      return $app->redirect('/');
    }

    $fail_redirect = $request->request->get('fail_redirect', '');
    $destination = $request->request->get('destination', '');

    // Failed log in throws an exception, so catch it here to avoid generic error.
    try {
      $response_raw = $app['drupal']->post('user/login', [
        'body' => json_encode([
          'username' => $request->request->get('username', ''),
          'password' => $request->request->get('password', ''),
        ]),
      ]);
    }
    catch (GuzzleHttp\Exception\ClientException $e) {

      $status = $e->getResponse()->getStatusCode();

      // Log in failed.
      if ($status == 401) {

        $this->flashMessage('message.alert', 'login.fail');

        $path = $fail_redirect;
        if (!$fail_redirect) {
          $path = '/login';
          if ($destination) {
            $path .= '?destination=' . urlencode($destination);
          }
        }

        return $app->redirect($path);
      }
      else {
        throw $e;
      }
    }

    $response = $response_raw->json();

    $this->processAuthentication($response);

    return $app->redirect('/' . $destination);
  }

  public function logout(Request $request, Application $app) {

    $this->app = $app;

    try {
      $app['drupal']->post('user/logout');
    }
    catch (\Exception $e) {
      // Swallow this exception. It'll only fail if the user is already logged out.
      // In that case we may as well log the user out of the front end too and pretend the whole thing worked.
    }
    $app['session']->invalidate();

    $this->flashMessage('message.success', 'logout.success');

    return $app->redirect($this->redirects['logout']);
  }

  /**
   * Processes the response of an authentication.
   * Does things like save user data to the session.
   * Split out so behaviour can be overridden.
   * @param $response
   */
  protected function processAuthentication($response) {

    $app = $this->app;

    $app['session']->set('user', $response['user']);

    $app['session']->set('drupal', array(
      'sessid' => $response['sessid'],
      'session_name' => $response['session_name'],
      'token' => $response['token'],
    ));

    $this->flashMessage('message.success', 'login.success');
  }

  /**
   * Override this to react to registration of new users.
   */
  protected function processRegistration($account) {

  }

  /**
   * Displays the registration form.
   * @param Request $request
   * @param Application $app
   * @return String
   */
  public function registerForm(Request $request, Application $app) {

    if ($app['drupal']->userIsLoggedIn()) {
      return $app->redirect($this->redirects['already.registered']);
    }

    return $app['twig']->render('page-register.twig');
  }

  /**
   * Build the request body that'll be used to register an account.
   * This method can be overridden to add extra fields, etc.
   * @param $request
   * @return array
   */
  public function createAccountToRegister($request) {

    $email = $request->request->get('email', '');
    $password = $request->request->get('password', '');

    $account = new \stdClass();
    $account->name = $email;
    $account->mail = $email;
    $account->pass = $password;
    $account->status = 1; // Initially not blocked. May change depending on signup workflow.

    return $account;
  }

  /**
   * Accepts data from register form to create a new user.
   * @param Request $request
   * @param Application $app
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function register(Request $request, Application $app) {

    $this->app = $app;

    if ($app['drupal']->userIsLoggedIn()) {
      return $app->redirect('/account/settings');
    }

    $password = $request->request->get('password', '');
    $password_confirm = $request->request->get('password_confirm', '');

    // If passwords don't match, return the user to the form with a message.
    if ($password !== $password_confirm) {
      $app['session']->getFlashBag()->add('message.alert', 'Your passwords did not match. Please try again.');
      return $app->redirect('/register');
    }

    // @todo: Validate user's email address.
    $account = $this->createAccountToRegister($request);

    $request_body = [
      'account' => $account,
    ];

    try {
      $response_raw = $app['drupal']->post('user/register', [
        'body' => json_encode($request_body),
      ]);
    }
    catch (GuzzleHttp\Exception\ClientException $e) {

      $status = $e->getResponse()->getStatusCode();

      // Not acceptable.
      // This is technically general form errors, but only seems to actually be email already exists, so handle as such.
      if ($status == 406) {

        // $e->GetMessage() is too horrible to display directly.
        $app['session']->getFlashBag()->add('message.alert', $e->GetMessage());
        $app['session']->getFlashBag()->add('message.alert', 'An account with that email address already exists in the system. Please log into your existing account or use a different email address.');

        return $app->redirect('/register');
      }
      else {
        throw $e;
      }
    }

    // Response is an array with keys ['uid'] and ['uri']
    // uri like: http://bcss-drupal.local/services/user/10
    // $response = $response_raw->json();

    // @todo: Need try/catch here, as new accounts are potentially blocked.
    // If that is the case, a \GuzzleHttp\Exception\ClientException is thrown with code 403.

    // NB, for this to work correctly, the following must be set at admin/config/people/accounts in the backend:
    // * Who can register accounts? Visitors.
    // * Require e-mail verification when a visitor creates an account. Unchecked.

    // Re-use the login code and log the user straight in:
    $response_raw = $app['drupal']->post('user/login', [
      'body' => json_encode([
        'username' => $account->name,
        'password' => $account->pass,
      ]),
    ]);

    $response = $response_raw->json();

    $this->processAuthentication($response);

    // Mechanism to react to a successful registration.
    // EG so BCSS can copy personalisation values saved in the session to
    // the new user object. This is down here so it can run when the user is logged in.
    $this->processRegistration($account);

    return $app->redirect($this->redirects['register.success']);
  }

  /**
   * Displays a flash message.
   * @param $message_type
   * @param $message_name
   */
  public function flashMessage($message_type, $message_name) {
    if (!empty($this->messages[$message_name])) {
      $this->app['session']->getFlashBag()->add($message_type, $this->messages[$message_name]);
    }
  }
}
