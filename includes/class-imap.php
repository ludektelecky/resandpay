<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimální IMAP klient (RFC 3501) nad šifrovaným socketem – bez rozšíření php-imap,
 * které od PHP 8.4 není součástí PHP. Umí jen to, co potřebuje kontrola plateb:
 * přihlášení, výběr složky, hledání podle odesílatele, stažení zprávy (bez označení
 * jako přečtená), přesun do složky a vytvoření složky.
 */
class Resandpay_Imap {

	/** @var resource|null */
	private $sock = null;

	private $tag = 0;

	/** @var array Schopnosti serveru (CAPABILITY). */
	public $caps = array();

	/** @var string Oddělovač složek. */
	public $delimiter = '/';

	/** @var string Poslední chyba. */
	public $error = '';

	/**
	 * Připojení.
	 *
	 * @param string $security ssl (implicitní TLS, port 993) | none (jen pro testy).
	 */
	public function connect( $host, $port = 993, $security = 'ssl', $timeout = 20 ) {
		$scheme  = 'ssl' === $security ? 'ssl://' : 'tcp://';
		$ctx     = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
					'SNI_enabled'      => true,
					'peer_name'        => $host,
				),
			)
		);
		$errno   = 0;
		$errstr  = '';
		$this->sock = @stream_socket_client( $scheme . $host . ':' . (int) $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $this->sock ) {
			/* translators: 1: server, 2: chyba */
			$this->error = sprintf( __( 'Nelze se připojit k %1$s (%2$s).', 'reserveandpay' ), $host . ':' . $port, $errstr ?: $errno );
			return false;
		}
		stream_set_timeout( $this->sock, $timeout );
		$greeting = $this->line();
		if ( 0 !== strpos( $greeting, '* OK' ) && 0 !== strpos( $greeting, '* PREAUTH' ) ) {
			$this->error = __( 'Server neodpověděl jako IMAP.', 'reserveandpay' ) . ' ' . trim( $greeting );
			return false;
		}
		$r = $this->command( 'CAPABILITY' );
		foreach ( $r['untagged'] as $u ) {
			if ( preg_match( '/^\* CAPABILITY (.+)$/i', $u, $m ) ) {
				$this->caps = array_map( 'strtoupper', explode( ' ', trim( $m[1] ) ) );
			}
		}
		return true;
	}

	/**
	 * Přihlášení. Přednostně AUTHENTICATE PLAIN (umí i hesla s diakritikou a speciálními
	 * znaky), jinak / při neúspěchu LOGIN. Chyba obsahuje odpověď serveru.
	 */
	public function login( $user, $pass ) {
		$ascii  = ! preg_match( '/[^\x20-\x7E]/', $user . $pass );
		$tried  = array();
		$status = '';
		if ( in_array( 'AUTH=PLAIN', $this->caps, true ) || ! $ascii ) {
			$payload = base64_encode( "\0" . $user . "\0" . $pass );
			$r       = in_array( 'SASL-IR', $this->caps, true )
				? $this->command( 'AUTHENTICATE PLAIN ' . $payload )
				: $this->command( 'AUTHENTICATE PLAIN', $payload );
			$tried[] = 'PLAIN';
			$status  = $r['status'];
			$ok      = $r['ok'];
		} else {
			$ok = false;
		}
		if ( ! $ok && $ascii && ! in_array( 'LOGINDISABLED', $this->caps, true ) ) {
			$r       = $this->command( 'LOGIN ' . $this->quote( $user ) . ' ' . $this->quote( $pass ) );
			$tried[] = 'LOGIN';
			$status  = $r['status'];
			$ok      = $r['ok'];
		}
		if ( ! $ok ) {
			$reply       = trim( preg_replace( '/^(NO|BAD)\s*/i', '', (string) $status ) );
			/* translators: 1: uživatel, 2: odpověď serveru */
			$this->error = sprintf( __( 'Přihlášení ke schránce se nezdařilo (uživatel %1$s). Server odpověděl: „%2$s“. Zkontrolujte heslo – nejlépe přihlášením se stejnými údaji do webmailu.', 'reserveandpay' ), $user, $reply ?: __( 'bez odpovědi', 'reserveandpay' ) );
			return false;
		}
		$r = $this->command( 'CAPABILITY' );
		foreach ( $r['untagged'] as $u ) {
			if ( preg_match( '/^\* CAPABILITY (.+)$/i', $u, $m ) ) {
				$this->caps = array_map( 'strtoupper', explode( ' ', trim( $m[1] ) ) );
			}
		}
		$r = $this->command( 'LIST "" ""' );
		foreach ( $r['untagged'] as $u ) {
			if ( preg_match( '/^\* LIST \([^)]*\) "(.)"/', $u, $m ) ) {
				$this->delimiter = $m[1];
			}
		}
		return true;
	}

	/** @return int|false Počet zpráv ve složce. */
	public function select( $folder = 'INBOX' ) {
		$r = $this->command( 'SELECT ' . $this->quote( self::utf7_encode( $folder ) ) );
		if ( ! $r['ok'] ) {
			/* translators: %s: složka */
			$this->error = sprintf( __( 'Složku %s nelze otevřít.', 'reserveandpay' ), $folder );
			return false;
		}
		foreach ( $r['untagged'] as $u ) {
			if ( preg_match( '/^\* (\d+) EXISTS/i', $u, $m ) ) {
				return (int) $m[1];
			}
		}
		return 0;
	}

	/** UID zpráv od odesílatele (vzestupně). */
	public function search_from( $sender ) {
		$r = $this->command( 'UID SEARCH FROM ' . $this->quote( $sender ) );
		$uids = array();
		foreach ( $r['untagged'] as $u ) {
			if ( preg_match( '/^\* SEARCH ?(.*)$/i', $u, $m ) ) {
				$uids = array_merge( $uids, array_map( 'intval', array_filter( explode( ' ', trim( $m[1] ) ), 'strlen' ) ) );
			}
		}
		sort( $uids );
		return $uids;
	}

	/** Celá zpráva (BODY.PEEK – zpráva se neoznačí jako přečtená). */
	public function fetch( $uid ) {
		$r = $this->command( 'UID FETCH ' . (int) $uid . ' BODY.PEEK[]' );
		return $r['ok'] && $r['literals'] ? $r['literals'][0] : '';
	}

	/** Existuje složka? Pokud ne, vytvoří ji. Vrací skutečný název (případně pod INBOX). */
	public function ensure_folder( $name ) {
		foreach ( array( $name, 'INBOX' . $this->delimiter . $name ) as $candidate ) {
			$r = $this->command( 'LIST "" ' . $this->quote( self::utf7_encode( $candidate ) ) );
			foreach ( $r['untagged'] as $u ) {
				if ( 0 === stripos( $u, '* LIST' ) ) {
					return $candidate;
				}
			}
		}
		foreach ( array( $name, 'INBOX' . $this->delimiter . $name ) as $candidate ) {
			if ( $this->command( 'CREATE ' . $this->quote( self::utf7_encode( $candidate ) ) )['ok'] ) {
				$this->command( 'SUBSCRIBE ' . $this->quote( self::utf7_encode( $candidate ) ) );
				return $candidate;
			}
		}
		/* translators: %s: složka */
		$this->error = sprintf( __( 'Složku %s nelze vytvořit.', 'reserveandpay' ), $name );
		return '';
	}

	/** Přesun zprávy do složky (MOVE, jinak COPY + smazání). */
	public function move( $uid, $folder ) {
		$f = $this->quote( self::utf7_encode( $folder ) );
		if ( in_array( 'MOVE', $this->caps, true ) ) {
			return $this->command( 'UID MOVE ' . (int) $uid . ' ' . $f )['ok'];
		}
		if ( ! $this->command( 'UID COPY ' . (int) $uid . ' ' . $f )['ok'] ) {
			return false;
		}
		$this->command( 'UID STORE ' . (int) $uid . ' +FLAGS.SILENT (\\Deleted)' );
		$cmd = in_array( 'UIDPLUS', $this->caps, true ) ? 'UID EXPUNGE ' . (int) $uid : 'EXPUNGE';
		return $this->command( $cmd )['ok'];
	}

	public function logout() {
		if ( $this->sock ) {
			$this->command( 'LOGOUT' );
			fclose( $this->sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->sock = null;
		}
	}

	/**
	 * Odešle příkaz a přečte odpověď včetně literálů {n}.
	 *
	 * @return array{ok:bool, untagged:array, literals:array, status:string}
	 */
	private function command( $cmd, $continuation = null ) {
		$out = array(
			'ok'       => false,
			'untagged' => array(),
			'literals' => array(),
			'status'   => '',
		);
		if ( ! $this->sock ) {
			return $out;
		}
		$tag = 'R' . ( ++$this->tag );
		fwrite( $this->sock, $tag . ' ' . $cmd . "\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		while ( true ) {
			$line = $this->line();
			if ( '' === $line ) {
				$this->error = __( 'Spojení se schránkou bylo přerušeno.', 'reserveandpay' );
				return $out;
			}
			// Literál: řádek končí {n} → následuje přesně n bajtů.
			while ( preg_match( '/\{(\d+)\}\r\n$/', $line, $m ) ) {
				$data              = $this->read( (int) $m[1] );
				$out['literals'][] = $data;
				$line             .= $this->line();
			}
			if ( null !== $continuation && 0 === strpos( $line, '+' ) ) {
				fwrite( $this->sock, $continuation . "\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$continuation = null;
				continue;
			}
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				$out['status'] = trim( substr( $line, strlen( $tag ) + 1 ) );
				$out['ok']     = 0 === stripos( $out['status'], 'OK' );
				return $out;
			}
			$out['untagged'][] = rtrim( $line, "\r\n" );
		}
	}

	private function line() {
		$l = fgets( $this->sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false === $l ? '' : $l;
	}

	private function read( $n ) {
		$data = '';
		while ( strlen( $data ) < $n ) {
			$chunk = fread( $this->sock, $n - strlen( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
		}
		return $data;
	}

	private function quote( $s ) {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $s ) . '"';
	}

	/** Názvy složek v modifikovaném UTF-7 (RFC 3501, např. „Zpracováno“ → „Zpracov&AOE-no“). */
	public static function utf7_encode( $s ) {
		$out = '';
		$buf = '';
		$len = mb_strlen( $s, 'UTF-8' );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = mb_substr( $s, $i, 1, 'UTF-8' );
			$o = mb_ord( $c, 'UTF-8' );
			if ( $o >= 0x20 && $o <= 0x7e ) {
				if ( '' !== $buf ) {
					$out .= '&' . str_replace( '/', ',', rtrim( base64_encode( mb_convert_encoding( $buf, 'UTF-16BE', 'UTF-8' ) ), '=' ) ) . '-';
					$buf  = '';
				}
				$out .= '&' === $c ? '&-' : $c;
			} else {
				$buf .= $c;
			}
		}
		if ( '' !== $buf ) {
			$out .= '&' . str_replace( '/', ',', rtrim( base64_encode( mb_convert_encoding( $buf, 'UTF-16BE', 'UTF-8' ) ), '=' ) ) . '-';
		}
		return $out;
	}
}
