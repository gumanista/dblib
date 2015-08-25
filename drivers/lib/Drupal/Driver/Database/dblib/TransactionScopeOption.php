<?php

/**
 * @file
 * Contains \Drupal\Driver\Database\dblib\TransactionScopeOption.
 */

namespace Drupal\Driver\Database\dblib;

class TransactionScopeOption extends Enum {
  const RequiresNew = 'RequiresNew';
  const Supress = 'Supress';
  const Required = 'Required';
}
