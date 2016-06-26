<?php

namespace Drupal\dblib\Driver;

class TransactionScopeOption extends Enum {
  const RequiresNew = 'RequiresNew';
  const Suppress = 'Suppress';
  const Required = 'Required';
}
