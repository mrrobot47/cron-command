<?php

use EE\Tests\TestCase;

/**
 * Base for cron-command tests: an empty `services/cron/` under EE_ROOT_DIR, running global containers, sites whose
 * compose project has a php service, and a parser for the ofelia INI config.
 */
abstract class CronTestCase extends TestCase {

	/** @var string The generated ofelia config. */
	protected $config_file;

	private $path_backup;

	private $error_log_backup;

	protected function setUp(): void {
		parent::setUp();
		exec( 'rm -rf ' . escapeshellarg( EE_SERVICE_DIR . '/cron' ) . ' ' . escapeshellarg( EE_SERVICE_DIR . '/docker-compose.yml' ) );
		mkdir( EE_SERVICE_DIR . '/cron/conf', 0755, true );
		$this->config_file = EE_SERVICE_DIR . '/cron/conf/config.ini';
		$this->set_config( [ 'custom-compose' => null ] );

		$this->runner->on( "/^docker inspect -f '\{\{\.State\.Running\}\}' services_global-/", "true\n" );
		$this->runner->on( '/^docker-compose -f docker-compose.yml\s+config --services$/', "nginx\nphp\ndb\n" );
		$this->runner->on( "/^cd (\S+) && docker-compose ps --format '\{\{\.Name\}\}'  php$/", [ $this, 'php_container' ] );

		$this->path_backup = getenv( 'PATH' );
		// EE::exec( …, true, true ) passes the job's stderr to error_log(), which would print into the test output.
		$this->error_log_backup = ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		putenv( 'PATH=' . $this->path_backup );
		ini_set( 'error_log', (string) $this->error_log_backup );
		parent::tearDown();
	}

	/**
	 * EE_DOCKER::container_status() first runs a real `which docker`; put a dummy docker on PATH so it passes.
	 * The dummy is never run: EE::exec()/launch() go to the fake runner.
	 */
	protected function docker_on_path() {
		$bin = $this->sandbox() . '/bin';
		mkdir( $bin );
		file_put_contents( "$bin/docker", "#!/bin/sh\nexit 0\n" );
		chmod( "$bin/docker", 0755 );
		putenv( "PATH=$bin:" . $this->path_backup );
		// init_global_container() would otherwise generate the global compose file.
		touch( EE_SERVICE_DIR . '/docker-compose.yml' );
	}

	/** Runner answer for `cd <site dir> && docker-compose ps … php`: `<dir name>-php-1`. */
	public function php_container( $command ) {
		preg_match( '/^cd (\S+) /', $command, $m );

		return [ basename( $m[1] ) . "-php-1\n", 0 ];
	}

	/** Insert an enabled site with its own directory (named like its compose project), so the php container is `<prefix>-php-1`. */
	protected function add_site( $site_url, array $fields = [] ) {
		$dir = $this->sandbox() . '/' . str_replace( '.', '', $site_url );
		mkdir( $dir );

		return $this->insert_site( $fields + [ 'site_url' => $site_url, 'site_type' => 'wp', 'site_fs_path' => $dir ] );
	}

	/** @return int Row id. */
	protected function insert_cron( $site_url, $command, $schedule, $user = null ) {
		return (int) EE::db()->table( 'cron' )->insert( array_filter( compact( 'site_url', 'command', 'schedule', 'user' ), 'is_string' ) );
	}

	/** @return array[] Cron rows without the id. */
	protected static function cron_rows() {
		return array_map(
			function ( $row ) {
				unset( $row['id'] );

				return $row;
			},
			EE\Tests\TestDB::rows( 'cron' )
		);
	}

	/**
	 * Parse an ofelia config the way gcfg reads it for these simple values: `[type "name"]` sections of `key = value` lines.
	 *
	 * @return array[] Each: type, name, values (key => value, in order), plus 'outside' => lines before the first section.
	 */
	protected static function parse_ini( $config ) {
		$sections = [];
		$outside  = [];
		foreach ( explode( "\n", $config ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( preg_match( '/^\[(\S+) "([^"]*)"\]$/', $line, $m ) ) {
				$sections[] = [ 'type' => $m[1], 'name' => $m[2], 'values' => [] ];
			} elseif ( preg_match( '/^([a-z-]+) = (.*)$/', $line, $m ) && $sections ) {
				$sections[ count( $sections ) - 1 ]['values'][ $m[1] ] = $m[2];
			} else {
				$outside[] = $line;
			}
		}

		return [ 'sections' => $sections, 'outside' => $outside ];
	}

	/** @return array[] The job sections of an ofelia config other than the CRON-1 keepalive job. */
	protected static function jobs( $config ) {
		return array_values(
			array_filter(
				self::parse_ini( $config )['sections'],
				function ( $section ) {
					return 'ee-keepalive' !== $section['name'];
				}
			)
		);
	}

	/** Run $fn and turn an unexpected EE::error() into a failure that shows its message. */
	protected function no_ee_error( callable $fn ) {
		try {
			return $fn();
		} catch ( EE\ExitException $e ) {
			$this->fail( 'Unexpected EE::error(): ' . trim( $this->stderr() ) );
		}
	}

	/**
	 * Split $string into words the way /bin/sh does, using only shell builtins (no PATH).
	 *
	 * @return string[]|null Words, or null when the shell can't parse it.
	 */
	protected static function sh_words( $string ) {
		$spec = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$proc = proc_open( [ '/bin/sh', '-c', "set -f; printf '%s\\0' $string" ], $spec, $pipes, null, [ 'PATH' => '/nonexistent' ] );
		$out  = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return 0 === proc_close( $proc ) ? explode( "\0", substr( $out, 0, -1 ) ) : null;
	}
}
