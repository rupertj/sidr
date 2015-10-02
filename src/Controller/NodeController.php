<?php

namespace SIDR\Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp;

class NodeController {

  /**
   * Loads a node from the backend, by the path that node should be displayed on.
   * @param $path String
   * @return mixed
   * @throws \GuzzleHttp\Exception
   */
  public function getNodeByPath($path) {

    $app = $this->app;

    // Pass prefix endpoint param, as we're passing the original path for this
    // content to Drupal and relying on sidr.module to intercept the request.
    $response = $app['drupal']->get($path, array('prefix_endpoint' => FALSE));
    $node = $response->json();

    $comment_response = $app['drupal']->get('node/' . $node['nid'] . '/comments');
    $comments = $comment_response->json();

    if ($comments) {
      $node['comments'] = array();

      // Process the comments before we pass them to the template
      foreach ($comments as $key => $comment) {

        $exploded_thread = explode('.', $comment['thread']);

        $comment_altered = $comment;
        $comment_altered['created'] = date("jS F Y", $comment['created']);
        $comment_altered['depth'] = count($exploded_thread) - 1;
        $comment_altered['thread_id'] = substr($exploded_thread[0], 0, 2);
        $comment_altered['flag_action'] = isset($comment['flag_action']) ? $comment['flag_action'] : "blocked";
        $comment_altered['author'] = $comment['display_name'];

        $node['comments'][$comment['cid']] = $comment_altered;
      }

      // Are any of the other comments children of the current comment?
      foreach ($node['comments'] as $key => $comment) {
        foreach ($node['comments'] as $recurs_key => $recurs_value) {
          // If the comment is in the same thread.
          if ($recurs_value['thread_id'] == $comment['thread_id'] && $recurs_value['depth'] > 0) {
            // @todo: sort the child comments by their actual thread values.
            $node['comments'][$comment['cid']]['replies'][] = $recurs_value;

            // Remove the reply because we've copied it into the right place.
            unset($node['comments'][$recurs_key]);
          }
        }
      }
      $node['comment_count'] = count($comments);
    }

    return $node;
  }

  /**
   * Views a node as a full page.
   * @param Request $request
   * @param Application $app
   * @return mixed
   */
  public function view(Request $request, Application $app) {

    $this->app = $app;

    $viewing_user = $app['session']->get('user', FALSE);

    $path = $request->getPathInfo();
    // Trim off leading /:
    $path = substr($path, 1);

    try {
      $node = $this->getNodeByPath($path);
    }
    catch (GuzzleHttp\Exception\ClientException $e) {

      $status = $e->getResponse()->getStatusCode();
      if ($status == 404) {
        return $app['twig']->render('404.twig', array(
          'title' => 'Page not found',
        ));
      }
      else {
        throw $e;
      }
    }

    $node = $this->processViewResult($node);

    // This should probably be in processViewResult above.
    $node['created'] = date("jS F Y", $node['created']);

    $template = $this->determineTemplate($node);

    if (!empty($node['body']['und'][0]['safe_value'])) {
      $content = $node['body']['und'][0]['safe_value'];
    }
    else {
      $content = '';
    }

    $node_author = '';
    $user_is_author = false;

    if ($node['uid'] != 0) {
      // Fetch the user info whose node we are looking at.
      $user_response = $app['drupal']->get('user/' . $node['uid']);

      if (!empty($user_response)) {
        $node_author = $user_response->json();

        if ($node_author['uid'] == $viewing_user['uid']) {
          $user_is_author = true;
        }
      }
    }

    return $app['twig']->render($template, array(
      'title' => $node['title'],
      'content' => $content, // @todo: Stop using this?
      'node' => $node,
      'user' => $viewing_user,
      'author' => $node_author,
      'user_is_author' => $user_is_author
    ));
  }

  /**
   * Override this to work with results before they're passed to a template.
   * @param $node
   * @return mixed
   */
  protected function processViewResult($node) {
    return $node;
  }

  /**
   * Override this to change how templates are chosen.
   * @param $node
   * @return string
   */
  protected function determineTemplate($node) {
    return 'page-node.twig';
  }

  /**
   * Shows the node add form.
   * @param Request $request
   * @param Application $app
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function add(Request $request, Application $app) {

    if (!$app['drupal.user']->isLoggedIn()) {
      $app['session']->getFlashBag()->add('message.info', 'Please log in to create content.');
      return $app->redirect('/login?destination=' . rawurlencode(trim($request->getPathInfo(), '/')));
    }

    $content_type = $request->attributes->get('content_type', FALSE);

    return $app['twig']->render('page-node-add.twig', array(
      'title' => 'Add content',
      'content_type' => $content_type,
    ));
  }

  /**
   * Submit method for the node add form.
   * @param Request $request
   * @param Application $app
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function addSubmit(Request $request, Application $app) {

    $this->app = $app;

    $user = $app['session']->get('user', FALSE);
    $destination = $request->request->get('destination', '');

    $node_title = $request->request->get('title', '');
    $node_body = $request->request->get('content', '');

    if (empty($node_title)) {
      $app['session']->getFlashBag()->add('message.alert', "Title field is required.");
    }

    if (empty($node_body)) {
      $app['session']->getFlashBag()->add('message.alert', "Description field is required.");
    }

    if (empty($node_title) || empty($node_body))  {
      return $app->redirect('/' . $destination);
    }

    $node = new \stdClass();
    $node->type = $request->request->get('content_type', 'article');
    $node->title = $node_title;
    $node->body['und'][0]['value'] = $node_body;
    if ($user) {
      $node->uid = $user['uid'];
    }

    $this->processSubmitRequest($node);

    $response_raw = $app['drupal']->post('entity_node', [
      'body' => json_encode($node),
    ]);

    $response = $response_raw->json();

    // First response is nid and uri. Fetch the whole node for the path.
    $response = $app['drupal']->get('node/' . $response['nid']);
    $node = $response->json();

    $this->processSubmitResponse($node);

    if ($destination) {
      return $app->redirect('/' . $destination);
    }
    else {
      return $app->redirect($this->fixNodePath($node['path']));
    }
  }

  /**
   * Fixes paths to nodes. The backend returns paths to the backend. Run them through this to change the path to the frontend path.
   * @param $backend_path String
   * @return String
   */
  public function fixNodePath($backend_path) {
    $url_parsed = parse_url($backend_path);
    return $url_parsed['path'];
  }

  public function edit() {
    // @todo: Populate
  }

  public function editSubmit(Request $request, Application $app) {
    // @todo: Populate
  }

  public function delete(Request $request, Application $app) {

    $content_type = $request->attributes->get('content_type', FALSE);
    $node_id = $request->attributes->get('node_id');

    return $app['twig']->render('page-node-delete.twig', array(
      'title' => 'Delete content',
      'content_type' => $content_type,
      'nid' => $node_id,
    ));
  }

  public function deleteSubmit(Request $request, Application $app) {

    $this->app = $app;

    $nid = $request->attributes->get('node_id');

    $this->processDeleteRequest($nid);

    $app['drupal']->deleteNode($nid);

    $app['session']->getFlashBag()->add('message.success', 'Success!');

    return $app->redirect('/collections');
  }

  /**
   * Implement this in a child class to react before a node is saved.
   * @param $node
   */
  protected function processSubmitRequest($node) {

  }

  /**
   * Implement this in a child class to react to a node being saved.
   * @param $node
   */
  protected function processSubmitResponse($node) {

  }

  /**
   * Implement this in a child class to react to a node being deleted.
   * @param $nid
   */
  protected function processDeleteRequest($nid) {

  }
}
