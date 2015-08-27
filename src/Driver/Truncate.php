<?php

/**
 * @file
 * Contains \Drupal\dblib\Driver\Truncate.
 */

namespace Drupal\dblib\Driver;

use Drupal\Core\Database\Query\Truncate as QueryTruncate;

class Truncate extends QueryTruncate { 
  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    return $prefix . 'TRUNCATE TABLE {' . $this->connection->escapeTable($this->table) . '} ';
  }
}
