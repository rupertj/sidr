<?php

namespace SIDR\Client;

/**
 * Class NodeClient
 * @package SIDR
 * A service for providing access to Drupal's nodes.
 */

class NodeClient {

  protected $client;
  protected $cache;

  public function __construct(DrupalClient $client, $cache) {
    $this->client = $client;
    $this->cache = $cache;
  }

  /**
   * Loads a node by ID.
   * @param $nid ID of the node to load.
   * @param bool $cache Whether to use the cache. Defaults to true.
   */
  public function get($nid, $cache = TRUE) {

    if ($cache) {
      $cache_key = 'node/' . $nid;

      $cache_result = $this->cache->get($cache_key);

      if ($cache_result) {
        return $cache_result;
      }
    }

    $node_response = $this->client->get('entity_node/' . $nid);
    $node = $node_response->json();

    if ($cache) {
      $this->cache->set($cache_key, $node);
    }

    return $node;
  }

  /**
   * Saves or updates a node as appropriate.
   * @param $node
   */
  public function save(&$node) {

    if (empty($node['nid'])) {
      // No nid, create one with a post.
      $response_raw = $this->client->post('node.json', [
        'body' => json_encode($node),
      ]);

      // Save the nid into the passed node so it can be used.
      $response = $response_raw->json();
      $node['nid'] = $response['nid'];
    }
    else {
      // Nid is set, update with a put.
      $this->client->put('entity_node/' . $node['nid'], array(
        'body' => json_encode($node),
      ));
    }
  }

  /**
   * Delete a node.
   * @param $nid
   */
  public function delete($nid) {
    $this->client->delete('entity_node/' . $nid);
  }
}