<?php

/**
 * @file
 * Contains \Drupal\Driver\Database\dblib\Truncate.
 */

namespace Drupal\Driver\Database\dblib;

use Drupal\Core\Database\Query\Truncate as QueryTruncate;

class Truncate extends QueryTruncate { 
  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    return $prefix . 'TRUNCATE TABLE {' . $this->connection->escapeTable($this->table) . '} ';
  }
}
