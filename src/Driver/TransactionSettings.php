<?php

namespace Drupal\dblib\Driver;

use Drupal\Core\Database\Database;

/**
 * Behaviour settings for a transaction.
 */
class TransactionSettings {

  /**
   * Summary of __construct
   *
   * @param mixed $Sane
   * @param TransactionScopeOption $ScopeOption
   * @param TransactionIsolationLevel $IsolationLevel
   */
  public function __construct($Sane = FALSE,
                              TransactionScopeOption $ScopeOption = NULL,
                              TransactionIsolationLevel $IsolationLevel = NULL) {
    $this->_Sane = $Sane;
    if ($ScopeOption == NULL) {
      $ScopeOption = TransactionScopeOption::RequiresNew;
    }
    if ($IsolationLevel == NULL) {
      $IsolationLevel = TransactionIsolationLevel::Unspecified();
    }
    $this->_IsolationLevel = $IsolationLevel;
    $this->_ScopeOption = $ScopeOption;
  }

  // @var TransactionIsolationLevel
  private $_IsolationLevel;

  // @var TransactionScopeOption
  private $_ScopeOption;

  // @var Boolean
  private $_Sane;

  /**
   * Summary of Get_IsolationLevel
   * @return mixed
   */
  public function Get_IsolationLevel() {
    return $this->_IsolationLevel;
  }

  /**
   * Summary of Get_ScopeOption
   * @return mixed
   */
  public function Get_ScopeOption() {
    return $this->_ScopeOption;
  }

  /**
   * Summary of Get_Sane
   * @return mixed
   */
  public function Get_Sane() {
    return $this->_Sane;
  }

  /**
   * Returns a default setting system-wide.
   *
   * @return TransactionSettings
   */
  public static function GetDefaults() {
    // Use snapshot if available.
    $isolation = TransactionIsolationLevel::Ignore;
    if ($info = Database::getConnection()->schema()->getDatabaseInfo()) {
      if ($info->snapshot_isolation_state == TRUE) {
        $isolation = TransactionIsolationLevel::Snapshot;
      }
    }
    // Otherwise use Drupal's default behaviour (except for nesting!)
    return new TransactionSettings(FALSE,
      TransactionScopeOption::Required,
      $isolation);
  }

  /**
   * Proposed better defaults.
   *
   * @return TransactionSettings
   */
  public static function GetBetterDefaults() {
    // Use snapshot if available.
    $isolation = TransactionIsolationLevel::Ignore;
    if ($info = Database::getConnection()->schema()->getDatabaseInfo()) {
      if ($info->snapshot_isolation_state == TRUE) {
        $isolation = TransactionIsolationLevel::Snapshot;
      }
    }
    // Otherwise use Drupal's default behaviour (except for nesting!)
    return new TransactionSettings(TRUE,
      TransactionScopeOption::Required,
      $isolation);
  }

  /**
   * Snapshot isolation is not compatible with DDL operations.
   *
   * @return TransactionSettings
   */
  public static function GetDDLCompatibleDefaults() {
    return new TransactionSettings(TRUE,
      TransactionScopeOption::Required,
      TransactionIsolationLevel::ReadCommitted);
  }

}
