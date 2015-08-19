<?php

namespace SIDR;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp;

class UserController {

  protected $app;

  /**
   * Displays the log in form to a user or redirects them appropriately.
   * @param Request $request
   * @param Application $app
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */

  public function login(Request $request, Application $app) {

    $this->app = $app;

    $destination = $request->query->get('destination', '');

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

    $destination = $request->request->get('destination', '/');

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

        $app['session']->getFlashBag()->add('message.alert', 'Log in failed. Please try again.');

        $path = '/login';
        if ($destination) {
          $path .= '?destination=' . urlencode($destination);
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

    $app['session']->getFlashBag()->add('message.success', 'You have been logged out.');

    return $app->redirect('/login');
  }

  /**
   * Account page.
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param \Silex\Application $app
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function viewAccount(Request $request, Application $app) {

    $this->app = $app;

    if (!$app['drupal']->userIsLoggedIn()) {
      $app['session']->getFlashBag()->add('message.warning', 'Please log in to view your account.');
      return $app->redirect('/login?destination=' . rawurlencode(trim($request->getPathInfo(), '/')));
    }

    $user = $app['session']->get('user');

    // Sanity check. Are we "logged in" as anon? If so, kill the session and
    // redirect to login page. @todo: Can we do this in the serviceprovider somehow?
    if ($user['uid'] == 0) {
      $app['session']->invalidate();
      return $app->redirect('/login?destination=account');
    }

    return $app['twig']->render('account.twig', array(
      'title' => $user['name'],
      'user' => $user,
    ));
  }

  public function updateAccount(Request $request, Application $app) {

    $this->app = $app;

    if (!$app['drupal']->userIsLoggedIn()) {
      return $app->redirect('/login?destination=account');
    }

    $display_name = $request->request->get('display_name', FALSE);
    $first_name = $request->request->get('first_name', FALSE);
    $last_name = $request->request->get('last_name', FALSE);
    $password = $request->request->get('password', FALSE);
    $password_confirm = $request->request->get('password_confirm', FALSE);
    $email = $request->request->get('email', FALSE);

    $user = $app['session']->get('user', FALSE);

    // Get the user again, just in case data's changed.
    $response_raw = $this->app['drupal']->get('user/' . $user['uid'] .'.json');
    $user = $response_raw->json();

    // Drupal services module expects a data layout like the user edit form.
    // NB integer fields post a different data structure to text fields for some reason.
    $user['first_name']['und'][0]['value'] = $first_name;
    $user['last_name']['und'][0]['value'] = $last_name;
    $user['display_name']['und'][0]['value'] = $display_name;

    // Mail is mail but also the Drupal username:
    $user['mail'] = $email;
    $user['name'] = $email;

    if ($password != '******' && ($password == $password_confirm)) {
      $user['pass'] = $password;
    }

    $response = $app['drupal']->put('user/' . $user['uid'], array(
      'body' => json_encode($user),
    ));

    $app['session']->getFlashBag()->add('message.success', 'Your account has been updated.');

    // Save updated user to the session.
    $response_raw = $app['drupal']->get('user/' . $user['uid'] .'.json');
    $app['session']->set('user', $response_raw->json());

    return $app->redirect('/account');
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

    $app['session']->getFlashBag()->add('message.success', 'You have been logged in');
  }

  /**
   * Override this to react to registration of new users.
   */
  protected function processRegistration($account) {

  }

  /**
   * Displays the registration form.
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param \Silex\Application $app
   */
  public function registerForm(Request $request, Application $app) {

    $this->app = $app;

    if ($app['drupal']->userIsLoggedIn()) {
      return $app->redirect('/account');
    }

    return $app['twig']->render('page-register.twig', array());
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

  public function register(Request $request, Application $app) {

    $this->app = $app;

    if ($app['drupal']->userIsLoggedIn()) {
      return $app->redirect('/account');
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

    // Call the existing method to react to an authentication.
    $this->processAuthentication($response);

    // Mechanism to react to a successful registration.
    // This is down here so it can run when the user is logged in.
    $this->processRegistration($account);

    return $app->redirect('/account');
  }
}
