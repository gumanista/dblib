<?php

namespace Drupal\dblib\Driver\EntityQuery\dblib;

use Drupal\Core\Entity\Query\Sql\Condition as BaseCondition;

/**
 * Implements entity query conditions for MSSQL databases.
 *
 * @see \Drupal\Core\Entity\Query\Sql\pgsql\Condition
 */
class Condition extends BaseCondition {

  /**
   * {@inheritdoc}
   */
  public static function translateCondition(array &$condition, $case_sensitive) {
    // @todo Implement and find proper namespace.
  }

}
