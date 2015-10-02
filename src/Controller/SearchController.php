<?php

namespace SIDR\Controller;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

class SearchController {

  /**
   * @param Application $app
   */
  public function __construct(Application $app) {
    $this->app = $app;
    $this->request = $app['request'];
    $this->solr = $app['solr'];
  }

  /**
   * Builds and returns a search results page.
   * @return mixed
   */
  public function search() {

    $app = $this->app;
    $solr = $this->solr;

    $query = $solr->createQuery(\Solarium\Client::QUERY_SELECT);

    $query->setFields($this->fields());

    $query_string = $app['request']->query->get('query', '');

    if ($query_string) {
      // @see http://wiki.solarium-project.org/index.php/V3:DisMax_component
      $query->setQuery($query_string);
    }

    // Limit the query to certain content types.
    $query->createFilterQuery('bundle')->setQuery('bundle:article OR bundle:location');

    // @todo: Build a pager...
    $query->setRows(9999);

    $results = $solr->execute($query);

    $rows = $this->processResults($results);

    return $app['twig']->render('page-search.twig', array(
      'title' => 'Search',
      'result_count' => $results->getNumFound(),
      'results' => $rows,
      'query_string' => $query_string
    ));
  }

  /**
   * Accepts the results from Solr and works on them before they're passed to the template.
   * @param $results
   * @return String
   */
  protected function processResults($results) {
    $rows = array();

    foreach ($results as $document) {

      $row = array();

      // Documents are iterable, to get all fields
      foreach ($document as $field => $value) {
        // this converts multivalue fields to a comma-separated string
        if (is_array($value)) {
          $value = implode(', ', $value);
        }

        $row[$field] = $value;
      }

      $rows[] = $row;
    }

    return $rows;
  }

  protected function fields() {
    return array(
      'entity_id',
      'label',
      'bundle',
      'teaser',
      'path_alias',
    );
  }
}