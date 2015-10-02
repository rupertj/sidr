<?php

namespace SIDR\ServiceProvider;

use Silex\Application;
use Silex\ServiceProviderInterface;
use GuzzleHttp\Client;
use SIDR\Client\DrupalClient;
use SIDR\Client\NodeClient;
use SIDR\Client\TaxonomyClient;
use SIDR\Client\UserClient;

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

        // @todo: This should probably be a service too.
        $guzzle_client = new Client();

        $drupal_client = new DrupalClient($app, $guzzle_client);
        $drupal_client->setBackend($app['drupal.backend'], $app['drupal.endpoint']);

        // Connect to Drupal.
        // The system works fine without it but session timeouts might happen.
        // Coomented for now for speed.
        # $client->post("system/connect.json");

        return $drupal_client;
      }
    );

    $app['drupal.node'] = $app->share(
      function () use ($app) {
        $nodeClient = new NodeClient($app['drupal'], $app['memcache']);
        return $nodeClient;
      }
    );

    $app['drupal.taxonomy'] = $app->share(
      function () use ($app) {
        $taxonomyClient = new TaxonomyClient($app['drupal'], $app['memcache']);
        return $taxonomyClient;
      }
    );

    $app['drupal.user'] = $app->share(
      function () use ($app) {
        $userClient = new UserClient($app['drupal'], $app['memcache'], $app['session']);
        return $userClient;
      }
    );
  }

  public function boot(Application $app) {
  }
}
