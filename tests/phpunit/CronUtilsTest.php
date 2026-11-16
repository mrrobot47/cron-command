<?php

use function EE\Cron\Utils\generate_cron_config;
use function EE\Cron\Utils\site_php_container;
use function EE\Cron\Utils\update_cron_config;

/**
 * The ofelia config generated from the cron table (src/helper/utils.php, templates/config.ini.mustache).
 */
class CronUtilsTest extends CronTestCase {

	public function test_site_and_host_jobs_become_job_exec_and_job_local_sections() {
		$this->add_site( 'a.test' );
		$this->insert_cron( 'a.test', "sh -c 'wp cron event run --due-now'", '0 */5 * * * *', 'www-data' );
		$this->insert_cron( 'host', "sh -c 'uptime'", '@every 1h' );

		$jobs = self::jobs( generate_cron_config() );

		$this->assertCount( 2, $jobs );
		$this->assertSame( 'job-exec', $jobs[0]['type'] );
		$this->assertMatchesRegularExpression( '/^a\.test-sh-c-wp-cron-event-run-due-now-[A-Za-z0-9]{5}$/', $jobs[0]['name'] );
		$this->assertSame(
			[
				'schedule'   => '0 */5 * * * *',
				'container'  => 'atest-php-1',
				'command'    => "sh -c 'wp cron event run --due-now'",
				'user'       => 'www-data',
				'no-overlap' => 'true',
			],
			$jobs[0]['values']
		);
		$this->assertSame( 'job-local', $jobs[1]['type'] );
		$this->assertMatchesRegularExpression( '/^host-sh-c-uptime-[A-Za-z0-9]{5}$/', $jobs[1]['name'] );
		$this->assertSame( [ 'schedule' => '@every 1h', 'command' => "sh -c 'uptime'", 'no-overlap' => 'true' ], $jobs[1]['values'] );
	}

	/** Commands are written raw: no HTML escaping of `&&`, `>` or quotes. */
	public function test_commands_are_not_html_escaped() {
		$this->insert_cron( 'host', "sh -c 'cd /tmp && echo \"<x>\" > out'", '@daily' );

		$jobs = self::jobs( generate_cron_config() );

		$this->assertSame( "sh -c 'cd /tmp && echo \"<x>\" > out'", $jobs[0]['values']['command'] );
	}

	/** Job names are unique even for identical commands (random suffix) and keep only `[A-Za-z0-9@-]` from the command. */
	public function test_job_names_are_unique_and_sanitised() {
		$this->insert_cron( 'host', "sh -c 'a@b.c/d'", '@daily' );
		$this->insert_cron( 'host', "sh -c 'a@b.c/d'", '@daily' );

		$names = array_column( self::jobs( generate_cron_config() ), 'name' );

		$this->assertCount( 2, array_unique( $names ) );
		foreach ( $names as $name ) {
			$this->assertMatchesRegularExpression( '/^host-sh-c-a@b-c-d-[A-Za-z0-9]{5}$/', $name );
		}
	}

	public function test_every_job_has_no_overlap() {
		$this->add_site( 'a.test' );
		$this->insert_cron( 'a.test', "sh -c 'one'", '@daily', 'www-data' );
		$this->insert_cron( 'a.test', "sh -c 'two'", '@hourly', 'root' );
		$this->insert_cron( 'host', "sh -c 'three'", '@weekly' );

		$jobs = self::jobs( generate_cron_config() );

		$this->assertSame( [ 'true', 'true', 'true' ], array_column( array_column( $jobs, 'values' ), 'no-overlap' ) );
		$this->assertSame( [ 'www-data', 'root' ], array_column( array_column( $jobs, 'values' ), 'user' ) );
	}

	public function test_no_jobs_renders_no_job_sections() {
		$config = generate_cron_config();

		$this->assertSame( [], self::jobs( $config ) );
		$this->assertSame( [], self::parse_ini( $config )['outside'] );
	}

	public function test_update_cron_config_writes_the_file_and_restarts_the_scheduler() {
		$this->insert_cron( 'host', "sh -c 'uptime'", '@hourly' );

		update_cron_config();

		$this->assertCount( 1, self::jobs( file_get_contents( $this->config_file ) ) );
		$this->assertSame( [ 'docker restart services_global-cron_1' ], $this->runner->commands() );
	}

	public function test_site_php_container_asks_compose_in_the_site_dir() {
		$this->add_site( 'my-site.test' );
		$dir = EE\Tests\TestDB::rows( 'sites' )[0]['site_fs_path'];

		$this->assertSame( 'my-sitetest-php-1', site_php_container( 'my-site.test' ) );
		$this->assertSame( [ "cd $dir && docker-compose ps --format '{{.Name}}'  php" ], $this->runner->commands() );
	}

	/**
	 * CRON-1 (fixed in v2.1.1): ofelia exits ("unable to start a empty scheduler") on a config without jobs, and `restart: always` loops it.
	 *
	 * @group CRON-1
	 */
	public function test_a_config_without_cron_jobs_still_has_a_job() {
		$this->assertNotEmpty( self::parse_ini( generate_cron_config() )['sections'] );
	}

	/**
	 * CRON-4 (fixed in v2.1.1): a user in a job-local section makes ofelia exit, so host jobs must never get one (rows from the update_cron_user migration have it).
	 *
	 * @group CRON-4
	 */
	public function test_host_jobs_never_get_a_user() {
		$this->insert_cron( 'host', "sh -c 'uptime'", '@hourly', 'www-data' );

		$jobs = self::jobs( generate_cron_config() );

		$this->assertArrayNotHasKey( 'user', $jobs[0]['values'] );
	}

	/**
	 * CRON-11 (fixed in v2.1.1): a stored five-field schedule (from `cron update`) is read by ofelia with the first field as seconds.
	 *
	 * @group CRON-11
	 */
	public function test_a_stored_five_field_schedule_gets_the_seconds_field() {
		$this->insert_cron( 'host', "sh -c 'uptime'", '*/5 * * * *' );

		$jobs = self::jobs( generate_cron_config() );

		$this->assertSame( '0 */5 * * * *', $jobs[0]['values']['schedule'] );
	}

	/**
	 * CRON-14 (fixed in v2.1.1): one stored command ofelia's INI parser rejects stops the scheduler for every job, so it must be left out.
	 *
	 * @group CRON-14
	 */
	public function test_a_stored_command_ofelia_cannot_parse_is_left_out() {
		$this->insert_cron( 'host', "sh -c 'echo \"unterminated'", '@hourly' );
		$this->insert_cron( 'host', "sh -c 'uptime'", '@hourly' );

		$jobs = self::jobs( generate_cron_config() );

		$this->assertSame( [ "sh -c 'uptime'" ], array_column( array_column( $jobs, 'values' ), 'command' ) );
	}
}
