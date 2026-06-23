<?php
/**
 * PostgreSQL database driver for WordPress (PDO).
 *
 * Define DB_DRIVER = 'WP_DB_Driver_PgSQL' in wp-config.php to activate.
 * Requires the pdo_pgsql PHP extension.
 *
 * SQL translation notes:
 *   - MySQL-specific management statements (SET sql_mode, SET NAMES, DO 1,
 *     SELECT @@…) are silently ignored; PostgreSQL equivalents are issued
 *     where possible (e.g. SET client_encoding for charset).
 *   - WordPress schema SQL (CREATE TABLE … ENGINE=InnoDB, AUTO_INCREMENT,
 *     etc.) requires a separate translation layer (e.g. the PG4WP drop-in).
 *     This driver handles the connection and data-access layer only.
 *
 * @package WordPress
 * @subpackage Database
 * @since 6.8.0
 */

class WP_DB_Driver_PgSQL implements WP_DB_Driver {

	/** @var PDO|null Active PDO connection. */
	private ?PDO $pdo = null;

	/** @var PDOStatement|null Result of the most recent query. */
	private ?PDOStatement $stmt = null;

	/** @var int Cursor position for fetch_field(). */
	private int $field_cursor = 0;

	/** @var int Row count from last mutating query. */
	private int $affected = 0;

	/** @var string Error message from last failed query. */
	private string $last_error_msg = '';

	/** @var int Error code from last failed query. */
	private int $last_error_no = 0;

	/** @var string Error message from last failed connect attempt. */
	private string $conn_error = '';

	/** @var int Error code from last failed connect attempt. */
	private int $conn_errno = 0;

	/** Patterns for MySQL management statements that PostgreSQL should ignore. */
	private const IGNORED_PATTERNS = [
		'/^\s*SET\s+(SESSION\s+)?sql_mode\b/i',
		'/^\s*SET\s+NAMES\b/i',
		'/^\s*DO\s+1\s*$/i',
		'/^\s*SELECT\s+@@/i',
	];

	public function connect( string $host, ?int $port, ?string $socket, string $user, string $pass, int $client_flags, string $dbname = '' ): bool {
		$dsn = 'pgsql:host=' . $host;
		if ( $port ) {
			$dsn .= ';port=' . $port;
		}
		if ( $dbname ) {
			$dsn .= ';dbname=' . $dbname;
		}

		try {
			$this->pdo = new PDO(
				$dsn,
				$user,
				$pass,
				[ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
			);
		} catch ( PDOException $e ) {
			$this->conn_error = $e->getMessage();
			$this->conn_errno = (int) $e->getCode();
			return false;
		}

		return true;
	}

	public function close(): bool {
		$this->pdo  = null;
		$this->stmt = null;
		return true;
	}

	public function is_connected(): bool {
		return $this->pdo !== null;
	}

	public function select_db( string $db ): bool {
		// PostgreSQL cannot switch databases at runtime; verify we are already on $db.
		try {
			$s = $this->pdo->query( 'SELECT current_database()' );
			return $s && ( $s->fetchColumn() === $db );
		} catch ( PDOException $e ) {
			return false;
		}
	}

	public function set_charset( string $charset ): bool {
		$pg = str_ireplace( [ 'utf8mb4', 'utf8' ], 'UTF8', $charset );
		try {
			$this->pdo->exec( "SET client_encoding TO '$pg'" );
			return true;
		} catch ( PDOException $e ) {
			return false;
		}
	}

	public function charset_name(): string {
		try {
			$s = $this->pdo->query( 'SHOW client_encoding' );
			return $s ? (string) $s->fetchColumn() : 'UTF8';
		} catch ( PDOException $e ) {
			return 'UTF8';
		}
	}

	public function query( string $sql ): bool {
		$this->stmt          = null;
		$this->field_cursor  = 0;
		$this->last_error_msg = '';
		$this->last_error_no  = 0;

		// Silently ignore MySQL-specific management statements.
		foreach ( self::IGNORED_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $sql ) ) {
				return true;
			}
		}

		try {
			$this->stmt     = $this->pdo->query( $sql );
			$this->affected = $this->stmt ? $this->stmt->rowCount() : 0;
			return $this->stmt !== false;
		} catch ( PDOException $e ) {
			$this->last_error_msg = $e->getMessage();
			$this->last_error_no  = (int) $e->getCode();
			return false;
		}
	}

	public function escape( string $str ): string {
		// PDO::quote() wraps in single quotes; strip them to match
		// mysqli_real_escape_string() which returns only the escaped content.
		$quoted = $this->pdo->quote( $str );
		return substr( $quoted, 1, -1 );
	}

	public function last_error(): string {
		return $this->last_error_msg;
	}

	public function last_errno(): int {
		return $this->last_error_no;
	}

	public function connect_error(): string {
		return $this->conn_error;
	}

	public function connect_errno(): int {
		return $this->conn_errno;
	}

	public function affected_rows(): int {
		return $this->affected;
	}

	public function insert_id(): int {
		// PostgreSQL requires calling lastval() after an INSERT into a SERIAL column.
		try {
			$s = $this->pdo->query( 'SELECT lastval()' );
			return $s ? (int) $s->fetchColumn() : 0;
		} catch ( PDOException $e ) {
			return 0;
		}
	}

	public function server_info(): string {
		try {
			return (string) $this->pdo->getAttribute( PDO::ATTR_SERVER_VERSION );
		} catch ( PDOException $e ) {
			return '';
		}
	}

	public function fetch_object(): ?object {
		if ( ! $this->stmt ) {
			return null;
		}
		$row = $this->stmt->fetch( PDO::FETCH_OBJ );
		return $row ?: null;
	}

	public function fetch_array(): array|false {
		if ( ! $this->stmt ) {
			return false;
		}
		$row = $this->stmt->fetch( PDO::FETCH_BOTH );
		return $row !== false ? $row : false;
	}

	public function has_result(): bool {
		return $this->stmt !== null;
	}

	public function free_result(): void {
		if ( $this->stmt ) {
			$this->stmt->closeCursor();
			$this->stmt = null;
		}
	}

	// PostgreSQL does not support multi-result sets.
	public function more_results(): bool {
		return false;
	}

	public function next_result(): bool {
		return false;
	}

	public function num_fields(): int {
		return $this->stmt ? $this->stmt->columnCount() : 0;
	}

	public function fetch_field(): object|false {
		if ( ! $this->stmt || $this->field_cursor >= $this->stmt->columnCount() ) {
			return false;
		}
		$meta = $this->stmt->getColumnMeta( $this->field_cursor++ );
		if ( ! $meta ) {
			return false;
		}
		return (object) [
			'name'       => $meta['name'],
			'table'      => $meta['table'] ?? '',
			'type'       => $meta['native_type'] ?? '',
			'max_length' => $meta['len'] ?? 0,
			'not_null'   => in_array( 'not_null', $meta['flags'] ?? [], true ) ? 1 : 0,
		];
	}
}
