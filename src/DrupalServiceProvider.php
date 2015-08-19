<?php

namespace SIDR;

use Silex\Application;
use Silex\ServiceProviderInterface;

/**
 * Class DrupalServiceProvider
 * @package SIDR
 *
 * Use this to make DrupalClient available in your Silex app.
 *
 * $app->register(new SIDR\DrupalServiceProvider(), [
 *   'drupal.backend' => 'http://example.com',
 *   'drupal.endpoint' => 'services',
 * ]);
 *
 * Once registered, it can be used like:
 *
 * $node_response = $app['drupal']->get('node/123');
 * $node = $node_response->json();
 *
 */
class DrupalServiceProvider implements ServiceProviderInterface {

  public function register(Application $app) {

    // @todo: Find a more appropriate way to handle missing config.
    if (!isset($app['drupal.backend'])) {
      $app['drupal.backend'] = '';
    }

    if (!isset($app['drupal.endpoint'])) {
      $app['drupal.endpoint'] = '';
    }

    $app['drupal'] = $app->share(
      function () use ($app) {

        $client = new DrupalClient($app);
        $client->setBackend($app['drupal.backend'], $app['drupal.endpoint']);

        // Connect to Drupal.
        // @todo: Check this is required. The system works fine without it but session timeouts might happen.
        $client->post("system/connect.json");

        return $client;
      }
    );
  }

  public function boot(Application $app) {
  }
}
