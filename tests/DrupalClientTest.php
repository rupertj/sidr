<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/Fakes.php';

class DrupalClientTest extends PHPUnit_Framework_TestCase {

  public function testNoCookies() {

    $app['memcache'] = FALSE;
    $app['session'] = new FakeSession();
    $app['timer'] = FALSE;
    $app['xdebug_backend_requests'] = FALSE;
    $app['debug_backend_requests'] = FALSE;

    $drupal = new \SIDR\DrupalClient($app, false);

    $this->assertEmpty($drupal->cookies());
  }

  public function testAuthenticatedCookies() {

    $session = new FakeSession();

    $session->set('drupal', array(
      'sessid' => 'SESSIDVALUE',
      'session_name' => 'SESSIONNAMEVALUE',
      'token' => 'TOKENVALUE',
    ));

    $app['memcache'] = FALSE;
    $app['session'] = $session;
    $app['timer'] = FALSE;
    $app['xdebug_backend_requests'] = FALSE;
    $app['debug_backend_requests'] = FALSE;

    $drupal = new \SIDR\DrupalClient($app, false);

    $cookies = $drupal->cookies();

    $this->assertArrayHasKey('SESSIONNAMEVALUE', $cookies);
    $this->assertEquals($cookies['SESSIONNAMEVALUE'], 'SESSIDVALUE');
  }

  public function testAnonHeaders() {

    $session = new FakeSession();

    $app['memcache'] = FALSE;
    $app['session'] = $session;
    $app['timer'] = FALSE;
    $app['xdebug_backend_requests'] = FALSE;
    $app['debug_backend_requests'] = FALSE;

    $drupal = new \SIDR\DrupalClient($app, false);
    $headers = $drupal->headers();

    $this->assertEquals(count($headers), 2);
    $this->assertEquals($headers['Accept'], 'application/json');
    $this->assertEquals($headers['Content-Type'], 'application/json');
  }

  public function testAuthenticatedHeaders() {

    $session = new FakeSession();

    $session->set('drupal', array(
      'sessid' => 'SESSIDVALUE',
      'session_name' => 'SESSIONNAMEVALUE',
      'token' => 'TOKENVALUE',
    ));

    $app['memcache'] = FALSE;
    $app['session'] = $session;
    $app['timer'] = FALSE;
    $app['xdebug_backend_requests'] = FALSE;
    $app['debug_backend_requests'] = FALSE;

    $drupal = new \SIDR\DrupalClient($app, false);
    $headers = $drupal->headers();

    $this->assertEquals(count($headers), 3);
    $this->assertEquals($headers['Accept'], 'application/json');
    $this->assertEquals($headers['Content-Type'], 'application/json');
    $this->assertEquals($headers['X-CSRF-Token'], 'TOKENVALUE');
  }

}
