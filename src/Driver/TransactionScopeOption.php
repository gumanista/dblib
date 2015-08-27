<?php

/**
 * @file
 * Contains \Drupal\dblib\Driver\TransactionScopeOption.
 */

namespace Drupal\dblib\Driver;

class TransactionScopeOption extends Enum {
  const RequiresNew = 'RequiresNew';
  const Supress = 'Supress';
  const Required = 'Required';
}
