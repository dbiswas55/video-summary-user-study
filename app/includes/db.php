<?php
/**
 * Database Connection
 * Prefers PDO MySQL, with a mysqli fallback for hosts where pdo_mysql is not
 * enabled. Both paths expose the small PDO-like API used by this app.
 */

if (!class_exists('DbMysqliStatement')) {
    class DbMysqliStatement {
        private $stmt;
        private $rows = [];
        private $cursor = 0;

        public function __construct(mysqli_stmt $stmt) {
            $this->stmt = $stmt;
        }

        public function execute($params = []) {
            $this->rows = [];
            $this->cursor = 0;

            if (!empty($params)) {
                $types = '';
                $values = [];
                foreach ($params as $value) {
                    if (is_int($value) || is_bool($value)) {
                        $types .= 'i';
                    } elseif (is_float($value)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                    $values[] = $value;
                }

                $refs = [];
                $refs[] = $types;
                foreach ($values as $idx => $value) {
                    $refs[] = &$values[$idx];
                }
                call_user_func_array([$this->stmt, 'bind_param'], $refs);
            }

            $ok = $this->stmt->execute();
            if ($this->stmt->field_count > 0) {
                $this->stmt->store_result();
                $meta = $this->stmt->result_metadata();
                $fields = $meta ? $meta->fetch_fields() : [];
                $row = [];
                $bind = [];

                foreach ($fields as $field) {
                    $row[$field->name] = null;
                    $bind[] = &$row[$field->name];
                }

                if ($bind) {
                    call_user_func_array([$this->stmt, 'bind_result'], $bind);
                    while ($this->stmt->fetch()) {
                        $assoc = [];
                        foreach ($fields as $field) {
                            $assoc[$field->name] = $row[$field->name];
                        }
                        $this->rows[] = $assoc;
                    }
                }

                if ($meta) {
                    $meta->free();
                }
                $this->stmt->free_result();
            }

            return $ok;
        }

        public function fetch($mode = null) {
            if (!array_key_exists($this->cursor, $this->rows)) {
                return false;
            }
            $row = $this->rows[$this->cursor++];
            if ($mode === 3) { // PDO::FETCH_NUM
                return array_values($row);
            }
            return $row;
        }

        public function fetchAll($mode = null) {
            $rows = [];
            while (($row = $this->fetch($mode)) !== false) {
                $rows[] = $row;
            }
            return $rows;
        }

        public function fetchColumn($column = 0) {
            $row = $this->fetch(3);
            if ($row === false || !array_key_exists($column, $row)) {
                return false;
            }
            return $row[$column];
        }
    }
}

if (!class_exists('DbMysqliResult')) {
    class DbMysqliResult {
        private $result;

        public function __construct(mysqli_result $result) {
            $this->result = $result;
        }

        public function fetch($mode = null) {
            if ($mode === 3) { // PDO::FETCH_NUM
                return $this->result->fetch_array(MYSQLI_NUM) ?: false;
            }
            return $this->result->fetch_assoc() ?: false;
        }

        public function fetchAll($mode = null) {
            $rows = [];
            if ($mode === 3) { // PDO::FETCH_NUM
                while ($row = $this->result->fetch_array(MYSQLI_NUM)) {
                    $rows[] = $row;
                }
                return $rows;
            }

            while ($row = $this->result->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        }

        public function fetchColumn($column = 0) {
            $row = $this->fetch(3);
            if ($row === false || !array_key_exists($column, $row)) {
                return false;
            }
            return $row[$column];
        }
    }
}

if (!class_exists('DbMysqliConnection')) {
    class DbMysqliConnection {
        private $mysqli;
        private $inTransaction = false;

        public function __construct(array $db) {
            if (!class_exists('mysqli')) {
                throw new RuntimeException('Neither pdo_mysql nor mysqli is enabled for this PHP installation.');
            }

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $socket = (!empty($db['socket']) && file_exists($db['socket'])) ? $db['socket'] : null;
            $this->mysqli = new mysqli(
                $db['host'],
                $db['user'],
                $db['pass'],
                $db['name'],
                (int)$db['port'],
                $socket
            );
            $this->mysqli->set_charset($db['charset']);
        }

        public function prepare($sql) {
            return new DbMysqliStatement($this->mysqli->prepare($sql));
        }

        public function query($sql) {
            $result = $this->mysqli->query($sql);
            if ($result instanceof mysqli_result) {
                return new DbMysqliResult($result);
            }
            return $result;
        }

        public function exec($sql) {
            $this->mysqli->query($sql);
            return $this->mysqli->affected_rows;
        }

        public function beginTransaction() {
            $this->mysqli->begin_transaction();
            $this->inTransaction = true;
            return true;
        }

        public function commit() {
            $ok = $this->mysqli->commit();
            $this->inTransaction = false;
            return $ok;
        }

        public function rollBack() {
            $ok = $this->mysqli->rollback();
            $this->inTransaction = false;
            return $ok;
        }

        public function inTransaction() {
            return $this->inTransaction;
        }

        public function lastInsertId() {
            return (string)$this->mysqli->insert_id;
        }
    }
}

function getDb() {
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/../config/config.php';
        $db = $config['db'];

        try {
            if (class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true)) {
                // Build DSN — use socket on Mac/MAMP, TCP elsewhere
                if (!empty($db['socket']) && file_exists($db['socket'])) {
                    $dsn = "mysql:unix_socket={$db['socket']};dbname={$db['name']};charset={$db['charset']}";
                } else {
                    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
                }

                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false
                ];

                $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
            } else {
                $pdo = new DbMysqliConnection($db);
            }
        } catch (Throwable $e) {
            if ($config['debug']) {
                die('Database connection failed: ' . $e->getMessage());
            }
            die('Database connection failed. Please contact the administrator.');
        }
    }

    return $pdo;
}
