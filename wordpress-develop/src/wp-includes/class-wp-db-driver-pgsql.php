<?php
/**
 * PostgreSQL database driver for WordPress (PDO).
 *
 * Define DB_DRIVER = 'WP_DB_Driver_PgSQL' in wp-config.php to activate.
 * Requires the pdo_pgsql PHP extension.
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

	/** MySQL management statements that PostgreSQL should silently swallow. */
	private const IGNORED_PATTERNS = [
		'/^\s*SET\s+(SESSION\s+)?sql_mode\b/i',
		'/^\s*SET\s+NAMES\b/i',
		'/^\s*DO\s+1\s*$/i',
		'/^\s*SELECT\s+@@/i',
	];

	// -------------------------------------------------------------------------
	// Connection
	// -------------------------------------------------------------------------

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
		// PostgreSQL cannot switch databases at runtime; verify we are on the right one.
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

	// -------------------------------------------------------------------------
	// Query execution
	// -------------------------------------------------------------------------

	public function query( string $sql ): bool {
		$this->stmt           = null;
		$this->field_cursor   = 0;
		$this->last_error_msg = '';
		$this->last_error_no  = 0;

		foreach ( self::IGNORED_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $sql ) ) {
				return true;
			}
		}

		$statements = $this->translate( $sql );

		try {
			$last = null;
			foreach ( $statements as $s ) {
				$last = $this->pdo->query( $s );
			}
			$this->stmt     = $last ?: null;
			$this->affected = $this->stmt ? $this->stmt->rowCount() : 0;
			return true;
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

	// -------------------------------------------------------------------------
	// Error info
	// -------------------------------------------------------------------------

	public function last_error(): string   { return $this->last_error_msg; }
	public function last_errno(): int      { return $this->last_error_no; }
	public function connect_error(): string { return $this->conn_error; }
	public function connect_errno(): int   { return $this->conn_errno; }

	// -------------------------------------------------------------------------
	// Query metadata
	// -------------------------------------------------------------------------

	public function affected_rows(): int { return $this->affected; }

	public function insert_id(): int {
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

	// -------------------------------------------------------------------------
	// Result iteration
	// -------------------------------------------------------------------------

	public function fetch_object(): ?object {
		if ( ! $this->stmt ) {
			return null;
		}
		$row = $this->stmt->fetch( PDO::FETCH_OBJ );
		if ( ! $row ) {
			return null;
		}
		// wp_users / wp_posts define their PK as `ID` (uppercase) without backticks.
		// PostgreSQL folds unquoted DDL identifiers to lowercase, so the column
		// lands as "id". WordPress accesses it as ->ID, so alias it back.
		if ( isset( $row->id ) && ! isset( $row->ID ) ) {
			$row->ID = $row->id;
		}
		// wp_comments defines its PK as `comment_ID` (mixed case, no backticks).
		// Same fold: stored as "comment_id", accessed by WordPress as ->comment_ID.
		if ( isset( $row->comment_id ) && ! isset( $row->comment_ID ) ) {
			$row->comment_ID = $row->comment_id;
		}
		return $row;
	}

	public function fetch_array(): array|false {
		if ( ! $this->stmt ) {
			return false;
		}
		$row = $this->stmt->fetch( PDO::FETCH_BOTH );
		if ( $row === false ) {
			return false;
		}
		// Same case-fold aliasing as fetch_object() — see comments there.
		if ( isset( $row['id'] ) && ! isset( $row['ID'] ) ) {
			$row['ID'] = $row['id'];
		}
		if ( isset( $row['comment_id'] ) && ! isset( $row['comment_ID'] ) ) {
			$row['comment_ID'] = $row['comment_id'];
		}
		return $row;
	}

	public function has_result(): bool { return $this->stmt !== null; }

	public function free_result(): void {
		if ( $this->stmt ) {
			$this->stmt->closeCursor();
			$this->stmt = null;
		}
	}

	public function more_results(): bool { return false; }
	public function next_result(): bool  { return false; }

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

	// -------------------------------------------------------------------------
	// MySQL → PostgreSQL translation
	// -------------------------------------------------------------------------

	/**
	 * Translates a MySQL SQL statement into one or more PostgreSQL statements.
	 *
	 * Returns an array because CREATE TABLE with KEYs becomes
	 * CREATE TABLE + several CREATE INDEX statements.
	 *
	 * @param string $sql MySQL SQL.
	 * @return string[] One or more PostgreSQL SQL statements.
	 */
	private function translate( string $sql ): array {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );

		if ( preg_match( '/^\s*CREATE\s+TABLE\b/i', $sql ) ) {
			return $this->translate_create_table( $sql );
		}

		if ( preg_match( '/^\s*ALTER\s+TABLE\b/i', $sql ) ) {
			return [ $this->translate_alter_table( $sql ) ];
		}

		if ( preg_match( '/^\s*SHOW\s+TABLES\b/i', $sql ) ) {
			return [ $this->translate_show_tables( $sql ) ];
		}

		if ( preg_match( '/^\s*SHOW\s+(?:FULL\s+)?COLUMNS\b/i', $sql ) ) {
			return [ $this->translate_show_columns( $sql ) ];
		}

		// General DML cleanup (INSERT IGNORE, REPLACE INTO, backticks, etc.)
		return [ $this->translate_dml( $sql ) ];
	}

	// ── CREATE TABLE ─────────────────────────────────────────────────────────

	private function translate_create_table( string $sql ): array {
		// Extract table name.
		if ( ! preg_match(
			'/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(/i',
			$sql,
			$m
		) ) {
			return [ $sql ];
		}
		$table = $m[1];

		// Isolate the column/key block (content inside the outermost parens).
		$open  = strpos( $sql, '(' );
		$close = strrpos( $sql, ')' );
		if ( $open === false || $close === false ) {
			return [ $sql ];
		}
		$inner = substr( $sql, $open + 1, $close - $open - 1 );

		$defs    = $this->split_column_defs( $inner );
		$pg_cols = [];
		$indexes = [];

		foreach ( $defs as $def ) {
			$def = trim( $def );
			if ( $def === '' ) {
				continue;
			}

			// UNIQUE KEY / UNIQUE INDEX
			if ( preg_match( '/^UNIQUE\s+(?:KEY|INDEX)\s+`?(\w+)`?\s*\((.+)\)/is', $def, $k ) ) {
				$idx_cols  = $this->strip_index_lengths( $k[2] );
				$indexes[] = "CREATE UNIQUE INDEX IF NOT EXISTS {$k[1]} ON {$table} ({$idx_cols})";
				continue;
			}

			// KEY / INDEX (non-unique)
			if ( preg_match( '/^(?:KEY|INDEX)\s+`?(\w+)`?\s*\((.+)\)/is', $def, $k ) ) {
				$idx_cols  = $this->strip_index_lengths( $k[2] );
				$indexes[] = "CREATE INDEX IF NOT EXISTS {$k[1]} ON {$table} ({$idx_cols})";
				continue;
			}

			// PRIMARY KEY — keep as-is inside CREATE TABLE.
			if ( preg_match( '/^PRIMARY\s+KEY/i', $def ) ) {
				// Strip length specs from primary key columns.
				$def       = preg_replace( '/\((\d+)\)/', '', $def );
				$pg_cols[] = $def;
				continue;
			}

			// Column definition.
			$pg_cols[] = $this->translate_column( $def );
		}

		$col_block = implode( ",\n  ", $pg_cols );
		$statements = [ "CREATE TABLE IF NOT EXISTS {$table} (\n  {$col_block}\n)" ];

		foreach ( $indexes as $idx ) {
			$statements[] = $idx;
		}

		return $statements;
	}

	/**
	 * Splits the inner body of a CREATE TABLE on commas, respecting nested parens.
	 *
	 * @param string $inner Content between the outermost CREATE TABLE ( … ).
	 * @return string[]
	 */
	private function split_column_defs( string $inner ): array {
		$defs    = [];
		$depth   = 0;
		$current = '';

		for ( $i = 0, $len = strlen( $inner ); $i < $len; $i++ ) {
			$ch = $inner[ $i ];
			if ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				--$depth;
			} elseif ( $ch === ',' && $depth === 0 ) {
				$defs[]  = $current;
				$current = '';
				continue;
			}
			$current .= $ch;
		}

		if ( trim( $current ) !== '' ) {
			$defs[] = $current;
		}

		return $defs;
	}

	/**
	 * Translates a single MySQL column definition to PostgreSQL.
	 *
	 * @param string $def Raw MySQL column definition.
	 * @return string PostgreSQL column definition.
	 */
	private function translate_column( string $def ): string {
		// Backticks → double-quotes.
		$def = str_replace( '`', '"', $def );

		$has_auto_increment = (bool) preg_match( '/\bAUTO_INCREMENT\b/i', $def );

		// Strip MySQL-only modifiers.
		$def = preg_replace( '/\bAUTO_INCREMENT\b/i', '', $def );
		$def = preg_replace( '/\bUNSIGNED\b/i', '', $def );
		$def = preg_replace( '/\bZEROFILL\b/i', '', $def );
		$def = preg_replace( '/\bCHARACTER\s+SET\s+\S+/i', '', $def );
		$def = preg_replace( '/\bCOLLATE\s+\S+/i', '', $def );

		// Map MySQL integer types.
		if ( $has_auto_increment ) {
			$def = preg_replace( '/\bBIGINT\s*\(\s*\d+\s*\)/i', 'BIGSERIAL', $def );
			$def = preg_replace( '/\b(?:MEDIUM)?INT\s*\(\s*\d+\s*\)/i', 'SERIAL', $def );
			$def = preg_replace( '/\bSMALLINT\s*\(\s*\d+\s*\)/i', 'SMALLSERIAL', $def );
		} else {
			$def = preg_replace( '/\bBIGINT\s*\(\s*\d+\s*\)/i', 'BIGINT', $def );
			$def = preg_replace( '/\bMEDIUMINT\s*\(\s*\d+\s*\)/i', 'INTEGER', $def );
			$def = preg_replace( '/\bSMALLINT\s*\(\s*\d+\s*\)/i', 'SMALLINT', $def );
			$def = preg_replace( '/\bTINYINT\s*\(\s*\d+\s*\)/i', 'SMALLINT', $def );
			$def = preg_replace( '/\bINT\s*\(\s*\d+\s*\)/i', 'INTEGER', $def );
		}

		// Text / blob types.
		$def = preg_replace( '/\b(?:TINY|MEDIUM|LONG)?TEXT\b/i', 'TEXT', $def );
		$def = preg_replace( '/\b(?:TINY|MEDIUM|LONG)?BLOB\b/i', 'BYTEA', $def );

		// Date / time types.
		$def = preg_replace( '/\bDATETIME\b/i', 'TIMESTAMP', $def );
		$def = preg_replace( '/\bYEAR\b/i', 'INTEGER', $def );

		// Numeric.
		$def = preg_replace( '/\bDOUBLE(\s+PRECISION)?\b/i', 'DOUBLE PRECISION', $def );

		// Fix MySQL zero-date default (invalid in PostgreSQL strict mode).
		$def = str_replace( "'0000-00-00 00:00:00'", "'1970-01-01 00:00:00'", $def );
		$def = str_replace( "'0000-00-00'", "'1970-01-01'", $def );

		// Collapse extra whitespace left by removals.
		$def = preg_replace( '/\s+/', ' ', $def );

		return trim( $def );
	}

	/**
	 * Removes column-length hints from index column lists (e.g. col(10) → col).
	 *
	 * @param string $cols Raw index column list.
	 * @return string
	 */
	private function strip_index_lengths( string $cols ): string {
		return preg_replace( '/`?(\w+)`?\s*\(\d+\)/', '$1', $cols );
	}

	// ── ALTER TABLE ──────────────────────────────────────────────────────────

	private function translate_alter_table( string $sql ): string {
		// Strip MySQL-only options from ADD COLUMN clauses.
		$sql = preg_replace( '/\bAUTO_INCREMENT\b/i', '', $sql );
		$sql = preg_replace( '/\bUNSIGNED\b/i', '', $sql );
		$sql = preg_replace( '/\bCHARACTER\s+SET\s+\S+/i', '', $sql );
		$sql = preg_replace( '/\bCOLLATE\s+\S+/i', '', $sql );

		// Strip ENGINE= / DEFAULT CHARACTER SET / AUTO_INCREMENT= table options.
		$sql = preg_replace( '/\bENGINE\s*=\s*\S+/i', '', $sql );
		$sql = preg_replace( '/\bDEFAULT\s+CHARACTER\s+SET\s+\S+/i', '', $sql );
		$sql = preg_replace( '/\bCONVERT\s+TO\s+CHARACTER\s+SET\s+\S+/i', '', $sql );
		$sql = preg_replace( '/\bAUTO_INCREMENT\s*=\s*\d+/i', '', $sql );

		// Type substitutions (same as column translation).
		$sql = preg_replace( '/\bBIGINT\s*\(\s*\d+\s*\)/i', 'BIGINT', $sql );
		$sql = preg_replace( '/\bMEDIUMINT\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql );
		$sql = preg_replace( '/\bSMALLINT\s*\(\s*\d+\s*\)/i', 'SMALLINT', $sql );
		$sql = preg_replace( '/\bTINYINT\s*\(\s*\d+\s*\)/i', 'SMALLINT', $sql );
		$sql = preg_replace( '/\bINT\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql );
		$sql = preg_replace( '/\b(?:TINY|MEDIUM|LONG)?TEXT\b/i', 'TEXT', $sql );
		$sql = preg_replace( '/\bDATETIME\b/i', 'TIMESTAMP', $sql );

		return preg_replace( '/\s+/', ' ', trim( $sql ) );
	}

	// ── SHOW TABLES ──────────────────────────────────────────────────────────

	private function translate_show_tables( string $sql ): string {
		// SHOW TABLES LIKE 'pattern' → SELECT tablename FROM pg_tables …
		if ( preg_match( '/SHOW\s+TABLES\s+LIKE\s+(.+)/i', $sql, $m ) ) {
			$like = trim( $m[1] );
			return "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE {$like}";
		}
		return "SELECT tablename FROM pg_tables WHERE schemaname = 'public'";
	}

	// ── SHOW COLUMNS ─────────────────────────────────────────────────────────

	private function translate_show_columns( string $sql ): string {
		// SHOW [FULL] COLUMNS FROM table [LIKE pattern]
		if ( ! preg_match( '/FROM\s+`?(\w+)`?/i', $sql, $m ) ) {
			return $sql;
		}
		$table = $m[1];
		return "SELECT column_name AS \"Field\", data_type AS \"Type\",
			        is_nullable AS \"Null\", '' AS \"Key\",
			        column_default AS \"Default\", '' AS \"Extra\"
			  FROM information_schema.columns
			 WHERE table_schema = 'public' AND table_name = '{$table}'
			 ORDER BY ordinal_position";
	}

	// ── General DML ──────────────────────────────────────────────────────────

	private function translate_dml( string $sql ): string {
		// Backticks → double-quotes.
		$sql = str_replace( '`', '"', $sql );

		// ON DUPLICATE KEY UPDATE → ON CONFLICT (col) DO UPDATE SET …
		if ( preg_match( '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql ) ) {
			return $this->translate_on_duplicate_key( $sql );
		}

		// INSERT IGNORE → INSERT … ON CONFLICT DO NOTHING
		if ( preg_match( '/^\s*INSERT\s+IGNORE\b/i', $sql ) ) {
			$sql = preg_replace( '/INSERT\s+IGNORE\b/i', 'INSERT', $sql );
			return rtrim( $sql ) . ' ON CONFLICT DO NOTHING';
		}

		// REPLACE INTO → INSERT … ON CONFLICT DO NOTHING
		if ( preg_match( '/^\s*REPLACE\s+INTO\b/i', $sql ) ) {
			$sql = preg_replace( '/REPLACE\s+INTO\b/i', 'INSERT INTO', $sql );
			return rtrim( $sql ) . ' ON CONFLICT DO NOTHING';
		}

		// Strip stray MySQL table options that can appear in DML contexts.
		$sql = preg_replace( '/\bENGINE\s*=\s*\S+/i', '', $sql );
		$sql = preg_replace( '/\bDEFAULT\s+CHARACTER\s+SET\s+\S+(?:\s+COLLATE\s+\S+)?/i', '', $sql );

		return preg_replace( '/\s+/', ' ', trim( $sql ) );
	}

	/**
	 * Translates MySQL ON DUPLICATE KEY UPDATE to PostgreSQL ON CONFLICT … DO UPDATE SET.
	 *
	 * Queries pg_index to find the table's primary/unique key so we can supply
	 * the required conflict target. Falls back to ON CONFLICT DO NOTHING if the
	 * table has no unique key yet (e.g. it was just created in the same request).
	 *
	 * @param string $sql MySQL INSERT … ON DUPLICATE KEY UPDATE … statement.
	 * @return string PostgreSQL equivalent.
	 */
	private function translate_on_duplicate_key( string $sql ): string {
		if ( ! preg_match( '/^(.*?)\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)$/is', $sql, $m ) ) {
			return $sql;
		}

		$insert_part = trim( $m[1] );
		$update_part = trim( $m[2] );

		// Table name.
		if ( ! preg_match( '/INTO\s+"?(\w+)"?/i', $insert_part, $tm ) ) {
			return $insert_part . ' ON CONFLICT DO NOTHING';
		}
		$table = $tm[1];

		// Extract the INSERT column list so we can pick the right conflict index.
		$insert_cols = [];
		if ( preg_match( '/\(([^)]+)\)\s+VALUES/i', $insert_part, $cm ) ) {
			$insert_cols = array_map(
				fn( $c ) => strtolower( trim( str_replace( '"', '', $c ) ) ),
				$this->split_top_level( $cm[1] )
			);
		}

		// Translate VALUES(col) → EXCLUDED.col in the SET list.
		$set_clauses = $this->translate_duplicate_set( $update_part );

		// Find the unique index whose columns are all present in the INSERT list.
		$conflict_cols = $this->find_conflict_index( $table, $insert_cols );

		if ( empty( $conflict_cols ) || empty( $set_clauses ) ) {
			return $insert_part . ' ON CONFLICT DO NOTHING';
		}

		$conflict = implode( ', ', $conflict_cols );
		$set      = implode( ', ', $set_clauses );

		return "{$insert_part} ON CONFLICT ({$conflict}) DO UPDATE SET {$set}";
	}

	/**
	 * Converts MySQL ON DUPLICATE KEY UPDATE assignment list to PostgreSQL SET clauses.
	 *
	 * col = VALUES(col)  →  "col" = EXCLUDED."col"
	 * col = 'literal'    →  "col" = 'literal'
	 *
	 * @param string $update_part Everything after ON DUPLICATE KEY UPDATE.
	 * @return string[]
	 */
	private function translate_duplicate_set( string $update_part ): array {
		$clauses = [];

		foreach ( $this->split_top_level( $update_part ) as $assignment ) {
			$assignment = trim( $assignment );

			if ( preg_match( '/^"?(\w+)"?\s*=\s*VALUES\s*\("?(\w+)"?\)$/i', $assignment, $a ) ) {
				$clauses[] = "\"{$a[1]}\" = EXCLUDED.\"{$a[2]}\"";
			} else {
				$clauses[] = $assignment;
			}
		}

		return $clauses;
	}

	/**
	 * Finds the unique/primary index on $table whose columns are all present in $insert_cols.
	 *
	 * MySQL's ON DUPLICATE KEY UPDATE fires on any unique constraint, but PostgreSQL's
	 * ON CONFLICT requires naming one specific index. We pick the index whose columns are
	 * a subset of what is being inserted, preferring the primary key.
	 *
	 * @param string   $table       Unquoted table name.
	 * @param string[] $insert_cols Lowercased column names from the INSERT list.
	 * @return string[] Double-quoted column names of the matching index, or [] if none.
	 */
	private function find_conflict_index( string $table, array $insert_cols ): array {
		static $cache = [];

		if ( ! array_key_exists( $table, $cache ) ) {
			try {
				// Fetch each unique/primary index as a group, primary key first.
				$s = $this->pdo->query( "
					SELECT i.indexrelid, i.indisprimary, a.attname
					  FROM pg_index     i
					  JOIN pg_attribute a ON a.attrelid = i.indrelid
					                    AND a.attnum    = ANY( i.indkey )
					 WHERE i.indrelid = " . $this->pdo->quote( $table ) . "::regclass
					   AND ( i.indisprimary OR i.indisunique )
					 ORDER BY i.indisprimary DESC, i.indexrelid, a.attnum
				" );
				$rows = $s ? $s->fetchAll( PDO::FETCH_ASSOC ) : [];
			} catch ( PDOException $e ) {
				$rows = [];
			}

			// Group columns by index OID.
			$indexes = [];
			foreach ( $rows as $row ) {
				$indexes[ $row['indexrelid'] ][] = $row['attname'];
			}
			$cache[ $table ] = array_values( $indexes );
		}

		$insert_set = array_flip( $insert_cols );

		// Return columns of the first index all of whose columns appear in the INSERT list.
		foreach ( $cache[ $table ] as $cols ) {
			$all_present = true;
			foreach ( $cols as $col ) {
				if ( ! isset( $insert_set[ strtolower( $col ) ] ) ) {
					$all_present = false;
					break;
				}
			}
			if ( $all_present ) {
				return array_map( fn( $c ) => "\"$c\"", $cols );
			}
		}

		return [];
	}

	/**
	 * Splits a comma-separated string at depth 0 (top-level commas only).
	 *
	 * @param string $str Input string.
	 * @return string[]
	 */
	private function split_top_level( string $str ): array {
		$parts   = [];
		$depth   = 0;
		$current = '';

		for ( $i = 0, $len = strlen( $str ); $i < $len; $i++ ) {
			$ch = $str[ $i ];
			if ( $ch === '(' ) {
				++$depth;
			} elseif ( $ch === ')' ) {
				--$depth;
			} elseif ( $ch === ',' && $depth === 0 ) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $ch;
		}

		if ( trim( $current ) !== '' ) {
			$parts[] = $current;
		}

		return $parts;
	}
}
