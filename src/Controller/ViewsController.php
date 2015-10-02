<?php

namespace SIDR\Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class ViewsController {

  protected static $viewsInfo;

  public static function setViewsInfo($viewsInfo) {
    self::$viewsInfo = $viewsInfo;
  }

  public function view(Request $request, Application $app) {

    $path = $request->getPathInfo();

    // Trim off leading /:
    $path = substr($path, 1);

    $response = $app['drupal']->get('views/' . $path);

    $title = '';

    foreach (self::$viewsInfo as $view) {
      if ($view['path'] == $path) {
        $title = $view['title'];
      }
    }

    return $app['twig']->render('view.twig', [
      'title' => $title,
      'rows' => $response->json(),
    ]);
  }
}