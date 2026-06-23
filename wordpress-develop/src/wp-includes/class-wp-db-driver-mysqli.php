<?php
/**
 * MySQLi database driver for WordPress.
 *
 * @package WordPress
 * @subpackage Database
 * @since 6.8.0
 */

class WP_DB_Driver_MySQLi implements WP_DB_Driver {

	/** @var mysqli|null Active connection. */
	private ?mysqli $conn = null;

	/** @var mysqli_result|bool|null Most recent query result. */
	private mixed $result = null;

	public function connect( string $host, ?int $port, ?string $socket, string $user, string $pass, int $client_flags, string $dbname = '' ): bool {
		mysqli_report( MYSQLI_REPORT_OFF );

		$this->conn = mysqli_init();

		if ( WP_DEBUG ) {
			mysqli_real_connect( $this->conn, $host, $user, $pass, null, $port, $socket, $client_flags );
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@mysqli_real_connect( $this->conn, $host, $user, $pass, null, $port, $socket, $client_flags );
		}

		if ( $this->conn->connect_errno ) {
			$this->conn = null;
			return false;
		}

		return true;
	}

	public function close(): bool {
		if ( ! $this->conn ) {
			return false;
		}
		return mysqli_close( $this->conn );
	}

	public function is_connected(): bool {
		return $this->conn instanceof mysqli;
	}

	public function select_db( string $db ): bool {
		return mysqli_select_db( $this->conn, $db );
	}

	public function set_charset( string $charset ): bool {
		return mysqli_set_charset( $this->conn, $charset );
	}

	public function charset_name(): string {
		return mysqli_character_set_name( $this->conn );
	}

	public function query( string $sql ): bool {
		$this->result = mysqli_query( $this->conn, $sql );
		return $this->result !== false;
	}

	public function escape( string $str ): string {
		return mysqli_real_escape_string( $this->conn, $str );
	}

	public function last_error(): string {
		return $this->conn ? mysqli_error( $this->conn ) : '';
	}

	public function last_errno(): int {
		return $this->conn ? mysqli_errno( $this->conn ) : 0;
	}

	public function connect_error(): string {
		return (string) mysqli_connect_error();
	}

	public function connect_errno(): int {
		return mysqli_connect_errno();
	}

	public function affected_rows(): int {
		return (int) mysqli_affected_rows( $this->conn );
	}

	public function insert_id(): int {
		return (int) mysqli_insert_id( $this->conn );
	}

	public function server_info(): string {
		return mysqli_get_server_info( $this->conn );
	}

	public function fetch_object(): ?object {
		if ( ! $this->result instanceof mysqli_result ) {
			return null;
		}
		$row = mysqli_fetch_object( $this->result );
		return $row ?: null;
	}

	public function fetch_array(): array|false {
		if ( ! $this->result instanceof mysqli_result ) {
			return false;
		}
		$row = mysqli_fetch_array( $this->result );
		return $row !== null ? $row : false;
	}

	public function has_result(): bool {
		return $this->result instanceof mysqli_result;
	}

	public function free_result(): void {
		if ( $this->result instanceof mysqli_result ) {
			mysqli_free_result( $this->result );
			$this->result = null;
		}
	}

	public function more_results(): bool {
		return $this->conn ? (bool) mysqli_more_results( $this->conn ) : false;
	}

	public function next_result(): bool {
		return $this->conn ? (bool) mysqli_next_result( $this->conn ) : false;
	}

	public function num_fields(): int {
		if ( ! $this->result instanceof mysqli_result ) {
			return 0;
		}
		return mysqli_num_fields( $this->result );
	}

	public function fetch_field(): object|false {
		if ( ! $this->result instanceof mysqli_result ) {
			return false;
		}
		return mysqli_fetch_field( $this->result );
	}
}
