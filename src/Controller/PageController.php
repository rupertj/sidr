<?php

namespace SIDR\Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class PageController {

  public function frontPage(Request $request, Application $app) {

    $response = $app['drupal']->get('views/articles');

    return $app['twig']->render('view-articles.twig', array(
      'title' => 'Blood Cancer Support Service',
      'rows' => $response->json(),
    ));
  }
}