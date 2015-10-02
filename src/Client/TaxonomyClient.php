<?php

namespace SIDR\Client;

/**
 * Class TaxonomyClient
 * @package SIDR
 * A service for providing access to Drupal's taxonomy.
 */

class TaxonomyClient {

  /** @var Array */
  protected $terms;

  /** @var \SIDR\DrupalClient */
  protected $client;

  protected $cache;

  public function __construct(DrupalClient $client, $cache) {
    $this->client = $client;
    $this->cache = $cache;
  }

  /**
   * Returns a flat list of terms for the requested vocabulary.
   * Response is cached if a cache is available.
   * @param $vocab_name Vocabulary machine name or Vocabulary ID.
   * @return mixed
   */
  public function getTermsForVocab($vocab_name) {

    // Check object first.
    if (isset($this->terms[$vocab_name])) {
      return $this->terms[$vocab_name];
    }

    // Then memcache.
    $cache_key = 'vocab/' . $vocab_name;
    $this->terms[$vocab_name] = $this->cache->get($cache_key);

    if ($this->terms[$vocab_name]) {
      return $this->terms[$vocab_name];
    }

    // Finally: Drupal.
    $result_raw = $this->client->post('taxonomy_vocabulary/getTree', [
      'body' => json_encode(array('vid' => $vocab_name)),
    ]);

    $result = $result_raw->json();

    // Build an array indexed by tid, as it's more useful:
    $terms = array();
    foreach ($result as $term) {
      $terms[$term['tid']] = $term;
    }

    $this->terms[$vocab_name] = $terms;

    // If we hit Drupal, set the result in memcache.
    $this->cache->set($cache_key, $this->terms[$vocab_name]);

    return $this->terms[$vocab_name];
  }

  /**
   * Returns a list of child terms of a specified parent.
   * Terms will be sorted by weight.
   * @param $vocab_name
   * @param int $parent
   * @return array
   */
  public function getTermsForVocabAtLevel($vocab_name, $parent = 0) {

    $terms = $this->getTermsForVocab($vocab_name);

    $terms_filtered = array();

    foreach ($terms as $term) {

      $this_parent = intval($term['parents'][0]);

      if ($parent == $this_parent) {
        $terms_filtered[] = $term;
      }
    }

    // Sort terms by weight.
    usort($terms_filtered, function ($a, $b) {
      if ($a['weight'] == $b['weight']) {
        return 0;
      }
      return ($a['weight'] > $b['weight']) ? 1 : -1;
    });

    return $terms_filtered;
  }

}