<?php

namespace Drupal\dblib\Driver;

use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\TransactionCommitFailedException;
use Drupal\Core\Database\TransactionNameNonUniqueException;
use Drupal\Core\Database\TransactionNoActiveException;
use Drupal\Core\Database\TransactionOutOfOrderException;
use Drupal\dblib\Driver\Utils as DatabaseUtils;

/**
 * MSSQL implementation of \Drupal\Core\Database\Connection.
 *
 * Temporary tables: temporary table support is done by means of global
 * temporary tables (#) to avoid the use of DIRECT QUERIES. You can enable and
 * disable the use of direct queries with $conn->directQuery = TRUE|FALSE.
 * http://blogs.msdn.com/b/brian_swan/archive/2010/06/15/ctp2-of-microsoft-driver-for-php-for-sql-server-released.aspx
 *
 */
class Connection extends DatabaseConnection {

  // Do not preprocess the query before execution.
  public $bypassQueryPreprocess = FALSE;

  // Prepare statements with SQLSRV_ATTR_DIRECT_QUERY = TRUE.
  public $directQuery = FALSE;

  // Wether to have or not statement caching.
  public $statementCaching = FALSE;

  /**
   * {@inheritdoc}
   *
   * @status tested
   */
  public function driver() {
    return 'dblib';
  }

  /**
   * {@inheritdoc}
   *
   * @status tested
   */
  public function databaseType() {
    return 'dblib';
  }

  /**
   * Error code for Login Failed, usually happens when
   * the database does not exist.
   */
  const DATABASE_NOT_FOUND = 28000;

  /**
   * The PDO constants do not matcc the actual isolation names
   * used in SQL.
   */
  private static function DefaultTransactionIsolationLevelInStatement() {
    return str_replace('_', ' ', DatabaseUtils::GetConfigConstant('MSSQL_DEFAULT_ISOLATION_LEVEL', FALSE));
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(\PDO $connection, array $connection_options) {
    // Needs to happen before parent construct.
    $this->statementClass = Statement::class;

    parent::__construct($connection, $connection_options);

    // This driver defaults to transaction support, except if explicitly passed FALSE.
    $this->transactionSupport = !isset($connection_options['transactions']) || $connection_options['transactions'] !== FALSE;
    $this->transactionalDDLSupport = $this->transactionSupport;

    // Store connection options for future reference.
    $this->connectionOptions = &$connection_options;

    // Fetch the name of the user-bound schema. It is the schema that SQL Server
    // will use for non-qualified tables.
    //$this->schema()->defaultSchema = $this->schema()->GetDefaultSchema();

    // Set default direct query behaviour
    $this->directQuery = TRUE;//DatabaseUtils::GetConfigBoolean('MSSQL_DEFAULT_DIRECTQUERY');
    $this->statementCaching = DatabaseUtils::GetConfigBoolean('MSSQL_STATEMENT_CACHING');
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {

    // Build the DSN.
    $options = [];
    $options['host'] = $connection_options['host'] . (!empty($connection_options['port']) ? ':' . $connection_options['port'] : '');
    // We might not have a database in the
    // connection options, for example, during
    // database creation in Install.
    if (!empty($connection_options['database'])) {
      $options['dbname'] = $connection_options['database'];
    }

    $options['charset'] = 'UTF-8';

    // Set isolation level if specified.
    if ($level = DatabaseUtils::GetConfigConstant('MSSQL_DEFAULT_ISOLATION_LEVEL', FALSE)) {
      $options['TransactionIsolation'] = $level;
    }

    // Build the DSN
    $dsn = 'dblib:';
    foreach ($options as $key => $value) {
      $dsn .= (empty($key) ? '' : "{$key}=") . $value . ';';
    }

    // PDO Options are set at a connection level.
    // and apply to all statements.
    $connection_options['pdo'] = [];

    // Set proper error mode for all statements
    $connection_options['pdo'][\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;

    // Set a Statement class, unless the driver opted out.
    // $connection_options['pdo'][\PDO::ATTR_STATEMENT_CLASS] = array(Statement::class, array(Statement::class));

    // Actually instantiate the PDO.
    $pdo = new \PDO($dsn, $connection_options['username'], $connection_options['password'], $connection_options['pdo']);

    return $pdo;
  }

  /**
   * Prepared PDO statements only makes sense if we cache them...
   *
   * @var mixed
   */
  private $statement_cache = [];

  /**
   * {@inheritdoc}
   *
   * @todo: remove that when DatabaseConnection::prepareQuery() is fixed to call
   *   $this->prepare() and not parent::prepare().
   *   https://www.drupal.org/node/2345451
   * @status: tested, temporary
   */
  public function prepareQuery($query, array $options = []) {

    // Merge default statement options. These options are
    // only specific for this preparation and will only override
    // the global configuration if set to different than NULL.
    $options = array_merge([
      'insecure' => FALSE,
      'statement_caching' => $this->statementCaching,
      'direct_query' => $this->directQuery,
      'prefix_tables' => TRUE,
    ], $options);

    // Prefix tables. There is no global setting for this.
    if ($options['prefix_tables'] !== FALSE) {
      $query = $this->prefixTables($query);
    }

    // The statement caching settings only affect the storage
    // in the cache, but if a statement is already available
    // why not reuse it!
    if (isset($this->statement_cache[$query])) {
      return $this->statement_cache[$query];
    }

    #region PDO Options

    $pdo_options = [];

    // Set insecure options if requested so.
    if ($options['insecure'] === TRUE) {
      // We have to log this, prepared statements are a security RISK.
      // watchdog('SQL Server Driver', 'An insecure query has been executed against the database. This is not critical, but worth looking into: %query', array('%query' => $query));
      // These are defined in class Connection.
      // This PDO options are INSECURE, but will overcome the following issues:
      // (1) Duplicate placeholders
      // (2) > 2100 parameter limit
      // (3) Using expressions for group by with parameters are not detected as equal.
      // This options are not applied by default, they are just stored in the connection
      // options and applied when needed. See {Statement} class.

      // We ask PDO to perform the placeholders replacement itself because
      // SQL Server is not able to detect duplicated placeholders in
      // complex statements.
      // E.g. This query is going to fail because SQL Server cannot
      // detect that length1 and length2 are equals.
      // SELECT SUBSTRING(title, 1, :length1)
      // FROM node
      // GROUP BY SUBSTRING(title, 1, :length2
      // This is only going to work in PDO 3 but doesn't hurt in PDO 2.
      // The security of parameterized queries is not in effect when you use \PDO::ATTR_EMULATE_PREPARES => true.
      // Your application should ensure that the data that is bound to the parameter(s) does not contain malicious
      // Transact-SQL code.
      // Never use this when you need special column binding.
      // THIS ONLY WORKS IF SET AT THE STATEMENT LEVEL.
      $pdo_options[\PDO::ATTR_EMULATE_PREPARES] = TRUE;
    }

    // It creates a cursor for the query, which allows you to iterate over the result set 
    // without fetching the whole result at once. A scrollable cursor, specifically, is one that allows 
    // iterating backwards.
    // https://msdn.microsoft.com/en-us/library/hh487158%28v=sql.105%29.aspx
    $pdo_options[\PDO::ATTR_CURSOR] = \PDO::CURSOR_SCROLL;

    #endregion

    // Call our overriden prepare.
    $stmt = $this->PDOPrepare($query, $pdo_options);

    // If statement caching is enabled, store current statement for reuse
    if ($options['statement_caching'] === TRUE) {
      $this->statement_cache[$query] = $stmt;
    }

    return $stmt;
  }

  /**
   * Internal function: prepare a query by calling PDO directly.
   *
   * This function has to be public because it is called by other parts of the
   * database layer, but do not call it directly, as you risk locking down the
   * PHP process.
   */
  public function PDOPrepare($query, array $options = []) {

    // Preprocess the query.
    if (!$this->bypassQueryPreprocess) {
      $query = $this->preprocessQuery($query);
    }

    // You can set the MSSQL_APPEND_CALLSTACK_COMMENT to TRUE
    // to append to each query, in the form of comments, the current
    // backtrace plus other details that aid in debugging deadlocks
    // or long standing locks. Use in combination with MSSQL profiler.
    global $conf;
    if (DatabaseUtils::GetConfigBoolean('MSSQL_APPEND_CALLSTACK_COMMENT') == TRUE) {
      global $user;
      $trim = strlen(DRUPAL_ROOT);
      $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
      static $request_id;
      if (empty($request_id)) {
        $request_id = uniqid('', TRUE);
      }
      // Remove las item (it's alwasy PDOPrepare)
      $trace = array_splice($trace, 1);
      $comment = PHP_EOL . PHP_EOL;
      $comment .= '-- uid:' . (empty($user) ? 'null' : $user->uid) . PHP_EOL;
      $uri = (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'none');
      $uri = preg_replace("/[^a-zA-Z0-9]/i", "_", $uri);
      $comment .= '-- url:' . $uri . PHP_EOL;
      //$comment .= '-- request_id:' . $request_id . PHP_EOL;
      foreach ($trace as $t) {
        $function = isset($t['function']) ? $t['function'] : '';
        $file = '';
        if (isset($t['file'])) {
          $len = strlen($t['file']);
          if ($len > $trim) {
            $file = substr($t['file'], $trim, $len - $trim) . " [{$t['line']}]";
          }
        }
        $comment .= '-- ' . str_pad($function, 35) . '  ' . $file . PHP_EOL;
      }
      $query = $comment . PHP_EOL . $query;
    }

    return parent::prepare($query, $options);
  }

  /**
   * This is the original replacement regexp from Microsoft.
   *
   * We could probably simplify it a lot because queries only contain
   * placeholders when we modify them.
   *
   * NOTE: removed 'escape' from the list, because it explodes
   * with LIKE xxx ESCAPE yyy syntax.
   */
  const RESERVED_REGEXP = '/\G
    # Everything that follows a boundary that is not : or _.
    \b(?<![:\[_])(?:
      # Any reserved words, followed by a boundary that is not an opening parenthesis.
      (action|admin|alias|any|are|array|at|begin|boolean|class|commit|contains|current|data|date|day|depth|domain|external|file|full|function|get|go|host|input|language|last|less|local|map|min|module|new|no|object|old|open|operation|parameter|parameters|path|plan|prefix|proc|public|ref|result|returns|role|row|rule|save|search|second|section|session|size|state|statistics|temporary|than|time|timestamp|tran|translate|translation|trim|user|value|variable|view|without)
      (?!\()
      |
      # Or a normal word.
      ([a-z]+)
    )\b
    |
    \b(
      [^a-z\'"\\\\]+
    )\b
    |
    (?=[\'"])
    (
      "  [^\\\\"] * (?: \\\\. [^\\\\"] *) * "
      |
      \' [^\\\\\']* (?: \\\\. [^\\\\\']*) * \'
    )
  /Six';

  /**
   * This method gets called between 3,000 and 10,000 times
   * on cold caches. Make sure it is simple and fast.
   *
   * @param mixed $matches
   *
   * @return mixed
   */
  protected function replaceReservedCallback($matches) {
    if ($matches[1] !== '') {
      // Replace reserved words. We are not calling
      // quoteIdentifier() on purpose.
      return '[' . $matches[1] . ']';
    }
    // Let other value passthru.
    // by the logic of the regex above, this will always be the last match.
    return end($matches);
  }

  public function quoteIdentifier($identifier) {
    return '[' . $identifier . ']';
  }

  /**
   * {@inheritdoc}
   */
  public function escapeField($field) {
    if ($cache = fastcache::cache_get($field, 'schema_escapeField')) {
      return $cache->data;
    }
    if (strlen($field) > 0) {
      $result = implode('.', array_map([
        $this,
        'quoteIdentifier',
      ], explode('.', preg_replace('/[^A-Za-z0-9_. ]+/', '', $field))));
    }
    else {
      $result = '';
    }
    fastcache::cache_set($field, $result, 'schema_escapeField');
    return $result;
  }

  public function quoteIdentifiers($identifiers) {
    return array_map([$this, 'quoteIdentifier'], $identifiers);
  }

  /**
   * {@inheritdoc}
   */
  public function escapeLike($string) {
    return addcslashes($string, '\%_[]');
  }

  /**
   * {@inheritdoc}
   */
  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    $query = $this->addRangeToQuery($query, $from, $count);
    return $this->query($query, $args, $options);
  }

  /**
   * Generates a temporary table name. Because we are using
   * global temporary tables, these are visible between
   * connections so we need to make sure that their
   * names are as unique as possible to prevent collisions.
   *
   * @return
   *   A table name.
   */
  protected function generateTemporaryTableName() {
    static $temp_key;
    if (!isset($temp_key)) {
      $temp_key = strtoupper(md5(uniqid(rand(), TRUE)));
    }
    return "db_temp_" . $this->temporaryNameIndex++ . '_' . $temp_key;
  }

  /**
   * {@inheritdoc}
   *
   * @status tested
   */
  public function queryTemporary($query, array $args = [], array $options = []) {
    // Generate a new GLOBAL temporary table name and protect it from prefixing.
    // SQL Server requires that temporary tables to be non-qualified.
    $tablename = '##' . $this->generateTemporaryTableName();
    // Temporary tables cannot be introspected so using them is limited on some scenarios.
    if ($options['real_table'] === TRUE) {
      $tablename = trim($tablename, "#");
    }
    $prefixes = $this->prefixes;
    $prefixes[$tablename] = '';
    $this->setPrefix($prefixes);

    // Having comments in the query can be tricky and break the SELECT FROM  -> SELECT INTO conversion
    $query = $this->schema()->removeSQLComments($query);

    // Replace SELECT xxx FROM table by SELECT xxx INTO #table FROM table.
    $query = preg_replace('/^SELECT(.*?)FROM/is', 'SELECT$1 INTO ' . $tablename . ' FROM', $query);
    $this->query($query, $args, $options);

    return $tablename;
  }

  /**
   * {@inheritdoc}
   *
   * This method is overriden to manage the insecure (EMULATE_PREPARE)
   * behaviour to prevent some compatibility issues with SQL Server.
   */
  public function query($query, array $args = [], $options = []) {

    // Use default values if not already set.
    $options += $this->defaultOptions();
    $stmt = NULL;

    try {
      // We allow either a pre-bound statement object or a literal string.
      // In either case, we want to end up with an executed statement object,
      // which we pass to PDOStatement::execute.
      if ($query instanceof StatementInterface) {
        $stmt = $query;
        $stmt->execute(NULL, $options);
      }
      else {
        $this->expandArguments($query, $args);
        $insecure = isset($options['insecure']) ? $options['insecure'] : FALSE;
        // Try to detect duplicate place holders, this check's performance
        // is not a good addition to the driver, but does a good job preventing
        // duplicate placeholder errors.
        $argcount = count($args);
        if ($insecure === TRUE || $argcount >= 2100 || ($argcount != substr_count($query, ':'))) {
          $insecure = TRUE;
        }
        $stmt = $this->prepareQuery($query, ['insecure' => $insecure]);
        $stmt->execute($args, $options);
      }

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return']) {
        case Database::RETURN_STATEMENT:
          return $stmt;
        case Database::RETURN_AFFECTED:
          $stmt->allowRowCount = TRUE;
          return $stmt->rowCount();
        case Database::RETURN_INSERT_ID:
          return $this->connection->lastInsertId();
        case Database::RETURN_NULL:
          return NULL;
        default:
          throw new \PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (\PDOException $e) {
      if ($options['throw_exception']) {
        // Wrap the exception in another exception, because PHP does not allow
        // overriding Exception::getMessage(). Its message is the extra database
        // debug information.
        if ($stmt instanceof StatementInterface) {
          $query_string = $stmt->getQueryString();
        }
        else {
          $query_string = $query;
        }
        $message = $e->getMessage() . ": " . $query_string . "; " . print_r($args, TRUE);
        // Match all SQLSTATE 23xxx errors.
        if (substr($e->getCode(), -6, -3) == '23') {
          $exception = new IntegrityConstraintViolationException($message, $e->getCode(), $e);
        }
        else {
          $exception = new DatabaseExceptionWrapper($message, 0, $e);
        }
        $exception->query_string = $query_string;
        $exception->args = $args;
        throw $exception;
      }
      return NULL;
    }
  }

  /**
   * Like query but with no insecure detection or query preprocessing.
   * The caller is sure that his query is MS SQL compatible! Used internally
   * from the schema class, but could be called from anywhere.
   *
   * @param mixed $query
   * @param array $args
   * @param mixed $options
   *
   * @throws \PDOException
   * @return mixed
   */
  public function query_direct($query, array $args = [], $options = []) {

    // Use default values if not already set.
    $options += $this->defaultOptions();
    $stmt = NULL;

    try {

      // Bypass query preprocessing and use direct queries.
      $ctx = new Context($this, TRUE, TRUE);

      $stmt = $this->prepareQuery($query, $options);
      $stmt->execute($args, $options);

      // Reset the context settings.
      unset($ctx);

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return']) {
        case Database::RETURN_STATEMENT:
          return $stmt;
        case Database::RETURN_AFFECTED:
          $stmt->allowRowCount = TRUE;
          return $stmt->rowCount();
        case Database::RETURN_INSERT_ID:
          return $this->connection->lastInsertId();
        case Database::RETURN_NULL:
          return NULL;
        default:
          throw new \PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (\PDOException $e) {
      if ($options['throw_exception']) {
        // Wrap the exception in another exception, because PHP does not allow
        // overriding Exception::getMessage(). Its message is the extra database
        // debug information.
        if ($stmt instanceof StatementInterface) {
          $query_string = $stmt->getQueryString();
        }
        else {
          $query_string = $query;
        }
        $message = $e->getMessage() . ": " . $query_string . "; " . print_r($args, TRUE);
        // Match all SQLSTATE 23xxx errors.
        if (substr($e->getCode(), -6, -3) == '23') {
          $exception = new IntegrityConstraintViolationException($message, $e->getCode(), $e);
        }
        else {
          $exception = new DatabaseExceptionWrapper($message, 0, $e);
        }
        $exception->query_string = $query_string;
        $exception->args = $args;
        throw $exception;
      }
      return NULL;
    }
  }

  /**
   * Internal function: massage a query to make it compliant with SQL Server.
   */
  public function preprocessQuery($query) {
    // Force quotes around some SQL Server reserved keywords.
    if (preg_match('/^SELECT/i', $query)) {
      $query = preg_replace_callback(self::RESERVED_REGEXP, [
        $this,
        'replaceReservedCallback',
      ], $query);
    }

    // Last chance to modify some SQL Server-specific syntax.
    $replacements = [];

    // Add prefixes to Drupal-specific functions.
    $defaultSchema = $this->schema()->GetDefaultSchema();
    foreach ($this->schema()->DrupalSpecificFunctions() as $function) {
      $replacements['/\b(?<![:.])(' . preg_quote($function) . ')\(/i'] = "{$defaultSchema}.$1(";
    }

    // Rename some functions.
    $funcs = [
      'LENGTH' => 'LEN',
      'POW' => 'POWER',
    ];

    foreach ($funcs as $function => $replacement) {
      $replacements['/\b(?<![:.])(' . preg_quote($function) . ')\(/i'] = $replacement . '(';
    }

    // Replace the ANSI concatenation operator with SQL Server poor one.
    $replacements['/\|\|/'] = '+';

    // Now do all the replacements at once.
    $query = preg_replace(array_keys($replacements), array_values($replacements), $query);

    return $query;
  }

  /**
   * Internal function: add range options to a query.
   *
   * This cannot be set protected because it is used in other parts of the
   * database engine.
   *
   * @status tested
   */
  public function addRangeToQuery($query, $from, $count) {
    if ($from == 0) {
      // Easy case: just use a TOP query if we don't have to skip any rows.
      $query = preg_replace('/^\s*SELECT(\s*DISTINCT)?/Dsi', 'SELECT$1 TOP(' . $count . ')', $query);
    }
    else {
      if ($this->schema()->EngineVersionNumber() >= 11) {
        // As of SQL Server 2012 there is an easy (and faster!) way to page results.
        $query = $query .= " OFFSET {$from} ROWS FETCH NEXT {$count} ROWS ONLY";
      }
      else {
        // More complex case: use a TOP query to retrieve $from + $count rows, and
        // filter out the first $from rows using a window function.
        $query = preg_replace('/^\s*SELECT(\s*DISTINCT)?/Dsi', 'SELECT$1 TOP(' . ($from + $count) . ') ', $query);
        $query = '
          SELECT * FROM (
            SELECT sub2.*, ROW_NUMBER() OVER(ORDER BY sub2.__line2) AS __line3 FROM (
              SELECT 1 AS __line2, sub1.* FROM (' . $query . ') AS sub1
            ) as sub2
          ) AS sub3
          WHERE __line3 BETWEEN ' . ($from + 1) . ' AND ' . ($from + $count);
      }
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function mapConditionOperator($operator) {
    // SQL Server doesn't need special escaping for the \ character in a string
    // literal, because it uses '' to escape the single quote, not \'. Sadly
    // PDO doesn't know that and interpret \' as an escaping character. We
    // use a function call here to be safe.
    static $specials = [
      'LIKE' => ['postfix' => " ESCAPE CHAR(92)"],
      'NOT LIKE' => ['postfix' => " ESCAPE CHAR(92)"],
    ];
    return isset($specials[$operator]) ? $specials[$operator] : NULL;
  }

  /**
   * {@inheritdoc}
   *
   * @status tested
   */
  public function nextId($existing = 0) {
    // If an exiting value is passed, for its insertion into the sequence table.
    if ($existing > 0) {
      try {
        $this->query_direct('SET IDENTITY_INSERT {sequences} ON; INSERT INTO {sequences} (value) VALUES(:existing); SET IDENTITY_INSERT {sequences} OFF', [':existing' => $existing]);
      }
      catch (\Exception $e) {
        // Doesn't matter if this fails, it just means that this value is already
        // present in the table.
      }
    }

    // Refactored to use OUTPUT because under high concurrency LAST_INSERTED_ID does not work properly.
    return $this->query_direct('INSERT INTO {sequences} OUTPUT (Inserted.[value]) DEFAULT VALUES')
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   *
   * @status needswork
   */
  public function escapeTable($table) {
    // A static cache is better suited for this.
    static $tables = [];
    if (isset($tables[$table])) {
      return $tables[$table];
    }

    // Rescue the # prefix from the escaping.
    $is_temporary = $table[0] == '#';
    $is_temporary_global = $is_temporary && isset($table[1]) && $table[1] == '#';

    // Any temporary table prefix will be removed.
    $result = preg_replace('/[^A-Za-z0-9_.]+/', '', $table);

    // Restore the temporary prefix.
    if ($is_temporary) {
      if ($is_temporary_global) {
        $result = '##' . $result;
      }
      else {
        $result = '#' . $result;
      }
    }

    $tables[$table] = $result;

    return $result;
  }

  #region Transactions

  /**
   * {@inheritdoc}
   */
  public function startTransaction($name = '', TransactionSettings $settings = NULL) {
    if ($settings == NULL) {
      $settings = TransactionSettings::GetDefaults();
    }
    return new Transaction($this, $name, $settings);
  }

  /**
   * {@inheritdoc}
   */
  public function rollback($savepoint_name = 'drupal_transaction') {
    if (!$this->supportsTransactions()) {
      return;
    }
    if (!$this->inTransaction()) {
      throw new TransactionNoActiveException();
    }
    // A previous rollback to an earlier savepoint may mean that the savepoint
    // in question has already been accidentally committed.
    if (!isset($this->transactionLayers[$savepoint_name])) {
      throw new TransactionNoActiveException();
    }

    // We need to find the point we're rolling back to, all other savepoints
    // before are no longer needed. If we rolled back other active savepoints,
    // we need to throw an exception.
    $rolled_back_other_active_savepoints = FALSE;
    while ($savepoint = array_pop($this->transactionLayers)) {
      if ($savepoint['name'] == $savepoint_name) {
        // If it is the last the transaction in the stack, then it is not a
        // savepoint, it is the transaction itself so we will need to roll back
        // the transaction rather than a savepoint.
        if (empty($this->transactionLayers)) {
          break;
        }
        if ($savepoint['started'] == TRUE) {
          $this->query_direct('ROLLBACK TRANSACTION ' . $savepoint['name']);
        }
        $this->popCommittableTransactions();
        if ($rolled_back_other_active_savepoints) {
          throw new TransactionOutOfOrderException();
        }
        return;
      }
      else {
        $rolled_back_other_active_savepoints = TRUE;
      }
    }
    $this->connection->rollBack();
    // Restore original transaction isolation level
    if ($level = static::DefaultTransactionIsolationLevelInStatement()) {
      if ($savepoint['settings']->Get_IsolationLevel() != TransactionIsolationLevel::Ignore) {
        if ($level != $savepoint['settings']->Get_IsolationLevel()) {
          $this->query_direct("SET TRANSACTION ISOLATION LEVEL {$level}");
        }
      }
    }
    if ($rolled_back_other_active_savepoints) {
      throw new TransactionOutOfOrderException();
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\dblib\Driver\TransactionSettings $settings
   *
   * @throws \Drupal\Core\Database\TransactionNameNonUniqueException
   */
  public function pushTransaction($name, $settings = NULL) {
    if ($settings == NULL) {
      $settings = TransactionSettings::GetBetterDefaults();
    }
    if (!$this->supportsTransactions()) {
      return;
    }
    if (isset($this->transactionLayers[$name])) {
      throw new TransactionNameNonUniqueException($name . " is already in use.");
    }
    $started = FALSE;
    // If we're already in a transaction.
    // TODO: Transaction scope Options is not working properly 
    // for first level transactions. It assumes that - always - a first level
    // transaction must be started.
    if ($this->inTransaction()) {
      switch ($settings->Get_ScopeOption()) {
        case TransactionScopeOption::RequiresNew:
          $this->query_direct('SAVE TRANSACTION ' . $name);
          $started = TRUE;
          break;
        case TransactionScopeOption::Required:
          // We are already in a transaction, do nothing.
          break;
        case TransactionScopeOption::Suppress:
          // The only way to suppress the ambient transaction is to use a new connection
          // during the scope of this transaction, a bit messy to implement.
          throw new \Exception('TransactionScopeOption::Suppress not implemented.');
      }
    }
    else {
      if ($settings->Get_IsolationLevel() != TransactionIsolationLevel::Ignore) {
        $current_isolation_level = strtoupper($this->schema()
          ->UserOptions()['isolation level']);
        // Se what isolation level was requested.
        $level = $settings->Get_IsolationLevel()->__toString();
        if (strcasecmp($current_isolation_level, $level) !== 0) {
          $this->query_direct("SET TRANSACTION ISOLATION LEVEL {$level}");
        }
      }
      // In order to start a transaction current statement cursors
      // must be closed.
      foreach ($this->statement_cache as $statement) {
        $statement->closeCursor();
      }
      $this->connection->beginTransaction();
    }
    // Store the name and settings in the stack.
    $this->transactionLayers[$name] = [
      'settings' => $settings,
      'active' => TRUE,
      'name' => $name,
      'started' => $started,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function popTransaction($name) {
    if (!$this->supportsTransactions()) {
      return;
    }
    // The transaction has already been committed earlier. There is nothing we
    // need to do. If this transaction was part of an earlier out-of-order
    // rollback, an exception would already have been thrown by
    // Database::rollback().
    if (!isset($this->transactionLayers[$name])) {
      return;
    }

    // Mark this layer as committable.
    $this->transactionLayers[$name]['active'] = FALSE;
    $this->popCommittableTransactions();
  }

  /**
   * {@inheritdoc}
   */
  protected function popCommittableTransactions() {
    // Commit all the committable layers.
    foreach (array_reverse($this->transactionLayers) as $name => $state) {
      // Stop once we found an active transaction.
      if ($state['active']) {
        break;
      }
      // If there are no more layers left then we should commit.
      unset($this->transactionLayers[$name]);
      if (empty($this->transactionLayers)) {
        try {
          // \PDO::commit() can either return FALSE or throw an exception itself
          if (!$this->connection->commit()) {
            throw new TransactionCommitFailedException();
          }
        }
        finally {
          // Restore original transaction isolation level
          if ($level = static::DefaultTransactionIsolationLevelInStatement()) {
            if ($state['settings']->Get_IsolationLevel() != TransactionIsolationLevel::Ignore) {
              if ($level != $state['settings']->Get_IsolationLevel()
                  ->__toString()
              ) {
                $this->query_direct("SET TRANSACTION ISOLATION LEVEL {$level}");
              }
            }
          }
        }
      }
      else {
        // Savepoints cannot be commited, only rolled back.
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createDatabase($database) {
    // Escape the database name.
    $database = Database::getConnection()->escapeDatabase($database);

    try {
      // Create the database and set it as active.
      $this->connection->exec("CREATE DATABASE $database COLLATE " . Schema::DEFAULT_COLLATION_CI);
    }
    catch (DatabaseException $e) {
      throw new DatabaseNotFoundException($e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function upsert($table, array $options = []) {
    $class = $this->getDriverClass('UpsertNative');
    return new $class($this, $table, $options);
  }

}

/**
 * @} End of "addtogroup database".
 */
