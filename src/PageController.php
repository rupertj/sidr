<?php

namespace SIDR;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class PageController {

  public function frontPage(Request $request, Application $app) {

    $response = $app['drupal']->get('views/articles');

    return $app['twig']->render('view-articles.twig', array(
      'title' => 'Front page title',
      'rows' => $response->json(),
    ));
  }
}
