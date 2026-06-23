<?php
/**
 * Database driver interface for WordPress.
 *
 * Abstracts all low-level database calls so that wpdb can operate against
 * different backends (MySQLi, PostgreSQL via PDO, etc.) without changes to
 * its public API.
 *
 * @package WordPress
 * @subpackage Database
 * @since 6.8.0
 */

interface WP_DB_Driver {

	/**
	 * Opens a connection to the database server.
	 *
	 * @param string      $host         Hostname or IP address.
	 * @param int|null    $port         TCP port, or null for the driver default.
	 * @param string|null $socket       Unix socket path, or null to use TCP.
	 * @param string      $user         Database username.
	 * @param string      $pass         Database password.
	 * @param int         $client_flags Bitmask of driver-specific client flags.
	 * @param string      $dbname       Database name (required for some drivers at connect time).
	 * @return bool True on success.
	 */
	public function connect( string $host, ?int $port, ?string $socket, string $user, string $pass, int $client_flags, string $dbname = '' ): bool;

	/**
	 * Closes the current connection.
	 *
	 * @return bool True on success.
	 */
	public function close(): bool;

	/**
	 * Returns whether an active connection exists.
	 *
	 * @return bool
	 */
	public function is_connected(): bool;

	/**
	 * Selects a database schema on the current connection.
	 *
	 * @param string $db Database name.
	 * @return bool True on success.
	 */
	public function select_db( string $db ): bool;

	/**
	 * Sets the connection character set.
	 *
	 * @param string $charset Character set name (e.g. 'utf8mb4').
	 * @return bool True on success.
	 */
	public function set_charset( string $charset ): bool;

	/**
	 * Returns the character set name currently in use by the connection.
	 *
	 * @return string
	 */
	public function charset_name(): string;

	/**
	 * Executes a SQL statement and stores the result internally.
	 *
	 * @param string $sql SQL statement to execute.
	 * @return bool True on success, false on error.
	 */
	public function query( string $sql ): bool;

	/**
	 * Escapes special characters in a string for use in a SQL statement.
	 *
	 * The returned string is NOT quoted; wrap it in single quotes yourself
	 * or use wpdb::prepare() instead.
	 *
	 * @param string $str Unescaped string.
	 * @return string Escaped string.
	 */
	public function escape( string $str ): string;

	/**
	 * Returns the error message from the last failed query.
	 *
	 * @return string
	 */
	public function last_error(): string;

	/**
	 * Returns the error code from the last failed query.
	 *
	 * @return int
	 */
	public function last_errno(): int;

	/**
	 * Returns the error message from the last failed connection attempt.
	 *
	 * @return string
	 */
	public function connect_error(): string;

	/**
	 * Returns the error code from the last failed connection attempt.
	 *
	 * @return int
	 */
	public function connect_errno(): int;

	/**
	 * Returns the number of rows affected by the last INSERT/UPDATE/DELETE.
	 *
	 * @return int
	 */
	public function affected_rows(): int;

	/**
	 * Returns the auto-generated ID from the last INSERT.
	 *
	 * @return int
	 */
	public function insert_id(): int;

	/**
	 * Returns the database server version string.
	 *
	 * @return string
	 */
	public function server_info(): string;

	/**
	 * Fetches the next row from the current result set as an object.
	 *
	 * @return object|null Row object, or null when no more rows.
	 */
	public function fetch_object(): ?object;

	/**
	 * Fetches the next row from the current result set as a numeric + associative array.
	 *
	 * @return array|false Row array, or false when no more rows or no result.
	 */
	public function fetch_array(): array|false;

	/**
	 * Returns whether the last query produced a result set.
	 *
	 * @return bool
	 */
	public function has_result(): bool;

	/**
	 * Frees the memory associated with the current result set.
	 *
	 * @return void
	 */
	public function free_result(): void;

	/**
	 * Checks whether additional result sets are available (multi-query).
	 *
	 * @return bool
	 */
	public function more_results(): bool;

	/**
	 * Advances to the next result set (multi-query).
	 *
	 * @return bool
	 */
	public function next_result(): bool;

	/**
	 * Returns the number of columns in the current result set.
	 *
	 * @return int
	 */
	public function num_fields(): int;

	/**
	 * Fetches metadata for the next column in the current result set.
	 *
	 * The returned object must have at minimum a `name` property holding
	 * the column name, matching the shape of mysqli_fetch_field().
	 *
	 * @return object|false Column metadata object, or false when exhausted.
	 */
	public function fetch_field(): object|false;
}
