<?php

/**
 * Cron_Command: create/update validation and storage, the `sh -c` wrapper, run-now, delete and list.
 */
class CronCommandTest extends CronTestCase {

	/** @var Cron_Command */
	private $command;

	protected function setUp(): void {
		parent::setUp();
		$this->docker_on_path();
		$this->command = new Cron_Command();
	}

	private function create( $site, $command, $schedule, $user = null ) {
		$this->command->create( [ $site ], array_filter( compact( 'command', 'schedule', 'user' ), 'is_string' ) );
	}

	private function wrap( $command ) {
		return self::call_private( $this->command, 'add_sh_c_wrapper', $command );
	}

	/** @return string[][] The table `cron list` printed: tab-separated when piped, ASCII boxes on a terminal. */
	private function listed( array $args, array $assoc_args = [] ) {
		$this->command->_list( $args, $assoc_args );

		$rows = [];
		foreach ( explode( "\n", trim( $this->stdout() ) ) as $line ) {
			if ( '+' === substr( $line, 0, 1 ) ) {
				continue;
			}
			$cells  = '|' === substr( $line, 0, 1 ) ? explode( '|', trim( $line, '|' ) ) : explode( "\t", $line );
			$rows[] = array_map( 'trim', $cells );
		}

		return $rows;
	}

	public function test_create_host_job_stores_the_row_and_regenerates_the_config() {
		$this->create( 'host', 'wp media regenerate --yes', '*/5 * * * *' );

		$this->assertSame( [ [ 'site_url' => 'host', 'user' => null, 'command' => "sh -c 'wp media regenerate --yes'", 'schedule' => '0 */5 * * * *' ] ], self::cron_rows() );
		$jobs = self::jobs( file_get_contents( $this->config_file ) );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'job-local', $jobs[0]['type'] );
		$this->assertSame( [ 'docker restart services_global-cron_1' ], $this->runner->matching( '/^docker restart/' ) );
		$this->assertStringContainsString( 'Success: Cron created successfully', $this->stdout() );
	}

	public function test_create_site_job_defaults_the_user_to_www_data() {
		$this->add_site( 'a.test' );

		$this->create( 'a.test', 'wp cron event run --due-now', '@every 10m' );
		$this->create( 'a.test/', 'wp cron event list', '@daily', 'root' );

		$this->assertSame(
			[
				[ 'site_url' => 'a.test', 'user' => 'www-data', 'command' => "sh -c 'wp cron event run --due-now'", 'schedule' => '@every 10m' ],
				[ 'site_url' => 'a.test', 'user' => 'root', 'command' => "sh -c 'wp cron event list'", 'schedule' => '@daily' ],
			],
			self::cron_rows()
		);
	}

	public function create_schedules() {
		return [
			'every minute'        => [ '* * * * *', '0 * * * * *' ],
			'surrounding spaces'  => [ '  30 2 * * 1-5 ', '0 30 2 * * 1-5' ],
			'steps and lists'     => [ '*/15 0,12 1-7 */2 mon', '0 */15 0,12 1-7 */2 mon' ],
			'helper'              => [ '@hourly', '@hourly' ],
			'every with duration' => [ '@every 1h10m2s', '@every 1h10m2s' ],
		];
	}

	/**
	 * A five-field schedule gets the seconds field ofelia expects; helpers are stored as given.
	 *
	 * @dataProvider create_schedules
	 */
	public function test_create_stores_the_schedule( $schedule, $stored ) {
		$this->create( 'host', 'true', $schedule );
		$this->assertSame( $stored, self::cron_rows()[0]['schedule'] );
	}

	public function wrong_field_counts() {
		return [ [ '* * * *' ], [ '0 * * * * *' ], [ '' ], [ '* * * * * *' ] ];
	}

	/** @dataProvider wrong_field_counts */
	public function test_create_rejects_a_schedule_without_five_fields( $schedule ) {
		$this->assert_ee_error(
			function () use ( $schedule ) {
				$this->create( 'host', 'true', $schedule );
			},
			'Schedule format should be same as Linux cron or schedule helper syntax'
		);
		$this->assertSame( [], self::cron_rows() );
	}

	public function unsupported_commands() {
		return [
			'semicolon' => [ 'wp cron event run; wp cache flush', 'Command chaining using `;` - semi-colon is not supported currently.' ],
			'hash'      => [ 'echo a#b', 'EasyEngine does not support commands with #' ],
		];
	}

	/**
	 * `;` and `#` start an INI comment in ofelia's config.
	 *
	 * @dataProvider unsupported_commands
	 */
	public function test_create_rejects_commands_the_ini_config_cannot_hold( $command, $message ) {
		$this->assert_ee_error(
			function () use ( $command ) {
				$this->create( 'host', $command, '@daily' );
			},
			$message
		);
		$this->assertSame( [], self::cron_rows() );
	}

	public function test_create_rejects_a_site_without_a_php_container() {
		$this->add_site( 'a.test', [ 'site_type' => 'html' ] );
		$this->runner->on( '/config --services$/', "nginx\n" );

		$this->assert_ee_error(
			function () {
				$this->create( 'a.test', 'true', '@daily' );
			},
			'a.test does not have PHP container.'
		);
	}

	public function invalid_schedules() {
		return [ [ '@bogus' ], [ '@every' ], [ '@every 10' ], [ '99 * * * *' ], [ 'a b c d e' ], [ '* 24 * * *' ], [ '* * 0 * *' ] ];
	}

	/**
	 * CRON-2 (fixed in v2.1.1): create only checks for a leading `@` or five fields, so schedules ofelia drops are stored.
	 *
	 * @group CRON-2
	 * @dataProvider invalid_schedules
	 */
	public function test_create_rejects_an_invalid_schedule( $schedule ) {
		$this->assert_ee_error(
			function () use ( $schedule ) {
				$this->create( 'host', 'true', $schedule );
			},
			'Schedule format should be same as Linux cron'
		);
	}

	/**
	 * CRON-4 (fixed in v2.1.1): ofelia refuses a `user` in a job-local section and the scheduler crash-loops, so host jobs must not take one.
	 *
	 * @group CRON-4
	 */
	public function test_create_rejects_a_user_for_a_host_job() {
		$this->assert_ee_error(
			function () {
				$this->create( 'host', 'true', '@daily', 'root' );
			}
		);
		$this->assertSame( [], self::cron_rows() );
	}

	public function ini_breaking_commands() {
		return [
			'backslash escape'   => [ 'printf a\\nb' ],
			'unbalanced quote'   => [ 'echo "unterminated' ],
			'trailing backslash' => [ 'echo a \\' ],
		];
	}

	/**
	 * CRON-14 (fixed in v2.1.1): ofelia's INI parser exits on these commands, which stops every site's cron.
	 *
	 * @group CRON-14
	 * @dataProvider ini_breaking_commands
	 */
	public function test_create_rejects_a_command_ofelia_cannot_parse( $command ) {
		$this->assert_ee_error(
			function () use ( $command ) {
				$this->create( 'host', $command, '@daily' );
			}
		);
	}

	public function test_add_sh_c_wrapper_wraps_a_plain_command() {
		$this->assertSame( "sh -c 'wp cron event run --due-now'", $this->wrap( 'wp cron event run --due-now' ) );
		$this->assertSame( [ 'sh', '-c', 'cd /var/www && wp cron event run' ], self::sh_words( $this->wrap( 'cd /var/www && wp cron event run' ) ) );
	}

	public function test_add_sh_c_wrapper_keeps_a_command_that_is_already_wrapped() {
		$this->assertSame( "sh -c 'wp cron event run'", $this->wrap( "sh -c 'wp cron event run'" ) );
	}

	public function sh_c_wrapper_cases() {
		return [
			'single quotes'       => [ "echo 'a b'" ],
			'quote in an arg'     => [ "wp option update blogname 'it'\\''s'" ],
			'sh -c further along' => [ "cd /var/www && sh -c 'wp cron event run'" ],
		];
	}

	/**
	 * CRON-5: the wrapper doesn't escape the command, and is skipped whenever `sh -c` appears anywhere in it.
	 *
	 * @group known-bug
	 * @group CRON-5
	 * @dataProvider sh_c_wrapper_cases
	 */
	public function test_add_sh_c_wrapper_runs_the_whole_command_in_sh( $command ) {
		$this->assertSame( [ 'sh', '-c', $command ], self::sh_words( $this->wrap( $command ) ) );
	}

	public function test_update_requires_something_to_update() {
		$id = $this->insert_cron( 'host', "sh -c 'true'", '@daily' );

		$this->assert_ee_error(
			function () use ( $id ) {
				$this->command->update( [ (string) $id ], [] );
			},
			'You should specify at least one of - site, command, schedule or user to update'
		);
	}

	public function test_update_changes_command_schedule_site_and_user() {
		$this->add_site( 'a.test' );
		$this->add_site( 'b.test' );
		$id    = $this->insert_cron( 'a.test', "sh -c 'true'", '@daily', 'www-data' );
		$other = $this->insert_cron( 'a.test', "sh -c 'keep'", '@daily', 'www-data' );

		$this->command->update( [ (string) $id ], [ 'command' => 'wp cron event run', 'schedule' => '@every 5m', 'site' => 'b.test', 'user' => 'root' ] );

		$this->assertSame(
			[
				[ 'site_url' => 'b.test', 'user' => 'root', 'command' => "sh -c 'wp cron event run'", 'schedule' => '@every 5m' ],
				[ 'site_url' => 'a.test', 'user' => 'www-data', 'command' => "sh -c 'keep'", 'schedule' => '@daily' ],
			],
			self::cron_rows()
		);
		$this->assertCount( 2, self::jobs( file_get_contents( $this->config_file ) ) );
		$this->assertSame( [ 'docker restart services_global-cron_1' ], $this->runner->matching( '/^docker restart/' ) );
		$this->assertStringContainsString( 'Success: Cron update Successfully', $this->stdout() );
	}

	public function test_update_validates_the_command() {
		$id = $this->insert_cron( 'host', "sh -c 'true'", '@daily' );

		$this->assert_ee_error(
			function () use ( $id ) {
				$this->command->update( [ (string) $id ], [ 'command' => 'a; b' ] );
			},
			'Command chaining using `;`'
		);
		$this->assertSame( "sh -c 'true'", self::cron_rows()[0]['command'] );
	}

	/**
	 * CRON-11 (fixed in v2.1.1): update stores a five-field schedule without the seconds field create adds, so ofelia runs `* * * * *` every second.
	 *
	 * @group CRON-11
	 */
	public function test_update_adds_the_seconds_field_like_create() {
		$this->add_site( 'a.test' );
		$id = $this->insert_cron( 'a.test', "sh -c 'true'", '@daily', 'www-data' );

		$this->command->update( [ (string) $id ], [ 'schedule' => '* * * * *' ] );

		$this->assertSame( '0 * * * * *', self::cron_rows()[0]['schedule'] );
	}

	public function valid_update_schedules() {
		return [ [ '*/5 * * * *' ], [ '30 2 * * *' ], [ '15,45 * * * *' ] ];
	}

	/**
	 * CRON-2 (fixed in v2.1.1): update counts non-space characters instead of fields, so it rejects valid schedules.
	 *
	 * @group CRON-2
	 * @dataProvider valid_update_schedules
	 */
	public function test_update_accepts_a_valid_schedule( $schedule ) {
		$this->add_site( 'a.test' );
		$id = $this->insert_cron( 'a.test', "sh -c 'true'", '@daily', 'www-data' );

		$this->no_ee_error(
			function () use ( $id, $schedule ) {
				$this->command->update( [ (string) $id ], [ 'schedule' => $schedule ] );
			}
		);
		$this->assertStringEndsWith( $schedule, self::cron_rows()[0]['schedule'] );
	}

	/**
	 * CRON-2 (fixed in v2.1.1): update accepts any five non-space characters as a schedule.
	 *
	 * @group CRON-2
	 */
	public function test_update_rejects_five_characters_that_are_not_a_schedule() {
		$id = $this->insert_cron( 'host', "sh -c 'true'", '@daily' );

		$this->assert_ee_error(
			function () use ( $id ) {
				$this->command->update( [ (string) $id ], [ 'schedule' => '12345' ] );
			},
			'Schedule format should be same as Linux cron'
		);
		$this->assertSame( '@daily', self::cron_rows()[0]['schedule'] );
	}

	/**
	 * CRON-6 (fixed in v2.1.1): update of an unknown id updates nothing and still reports success.
	 *
	 * @group CRON-6
	 */
	public function test_update_rejects_an_unknown_id() {
		$this->assert_ee_error(
			function () {
				$this->command->update( [ '99' ], [ 'schedule' => '@daily' ] );
			},
			'99'
		);
	}

	/**
	 * CRON-6 (fixed in v2.1.1): `--site` isn't validated, so a job moves to a site that doesn't exist (and gets no container).
	 *
	 * @group CRON-6
	 */
	public function test_update_rejects_an_unknown_site() {
		$this->add_site( 'a.test' );
		$id = $this->insert_cron( 'a.test', "sh -c 'true'", '@daily', 'www-data' );

		$this->assert_ee_error(
			function () use ( $id ) {
				$this->command->update( [ (string) $id ], [ 'site' => 'nope.test' ] );
			},
			'nope.test'
		);
		$this->assertSame( 'a.test', self::cron_rows()[0]['site_url'] );
	}

	/**
	 * CRON-4 (fixed in v2.1.1): update puts a user on a host job, which crash-loops ofelia.
	 *
	 * @group CRON-4
	 */
	public function test_update_rejects_a_user_for_a_host_job() {
		$id = $this->insert_cron( 'host', "sh -c 'true'", '@daily' );

		$this->assert_ee_error(
			function () use ( $id ) {
				$this->command->update( [ (string) $id ], [ 'user' => 'root' ] );
			}
		);
		$this->assertNull( self::cron_rows()[0]['user'] );
	}

	/**
	 * CRON-14 (fixed in v2.1.1): update stores commands that ofelia's INI parser exits on.
	 *
	 * @group CRON-14
	 */
	public function test_update_rejects_a_command_ofelia_cannot_parse() {
		$id = $this->insert_cron( 'host', "sh -c 'true'", '@daily' );

		$this->assert_ee_error(
			function () use ( $id ) {
				$this->command->update( [ (string) $id ], [ 'command' => 'printf a\\nb' ] );
			}
		);
	}

	public function test_run_now_runs_a_site_job_in_the_php_container() {
		$this->add_site( 'a.test' );
		$id   = $this->insert_cron( 'a.test', "sh -c 'wp cron event run --due-now'", '@daily', 'www-data' );
		$root = $this->insert_cron( 'a.test', "sh -c 'id'", '@daily' );

		$this->command->run_now( [ (string) $id ] );
		$this->command->run_now( [ (string) $root ] );

		$this->assertSame(
			[ "docker exec --user=www-data atest-php-1 sh -c 'wp cron event run --due-now'", "docker exec --user=root atest-php-1 sh -c 'id'" ],
			$this->runner->matching( '/^docker exec/' )
		);
	}

	public function test_run_now_errors_for_an_unknown_id() {
		$this->assert_ee_error(
			function () {
				$this->command->run_now( [ '42' ] );
			},
			'No such cron with id 42'
		);
	}

	/**
	 * CRON-8: run-now looks up a php container for host jobs too (array access on false, `cd  && docker-compose ps` in $HOME).
	 *
	 * @group known-bug
	 * @group CRON-8
	 */
	public function test_run_now_runs_a_host_job_directly() {
		$id = $this->insert_cron( 'host', "sh -c 'echo hi'", '@daily' );

		$this->command->run_now( [ (string) $id ] );

		$this->assertSame( [ "sh -c 'echo hi'" ], $this->runner->commands() );
	}

	/**
	 * CRON-7: run-now ignores the exit code, so a failing job exits 0.
	 *
	 * @group known-bug
	 * @group CRON-7
	 */
	public function test_run_now_fails_when_the_job_fails() {
		$this->add_site( 'a.test' );
		$id = $this->insert_cron( 'a.test', "sh -c 'false'", '@daily', 'www-data' );
		$this->runner->on( '/^docker exec --user=/', '', 1 );

		$this->assert_ee_error(
			function () use ( $id ) {
				$this->command->run_now( [ (string) $id ] );
			}
		);
	}

	public function test_delete_removes_the_job_and_regenerates_the_config() {
		$this->add_site( 'a.test' );
		$id = $this->insert_cron( 'a.test', "sh -c 'gone'", '@daily', 'www-data' );
		$this->insert_cron( 'a.test', "sh -c 'kept'", '@hourly', 'www-data' );

		$this->command->delete( [ (string) $id ] );

		$this->assertSame( [ "sh -c 'kept'" ], array_column( self::cron_rows(), 'command' ) );
		$jobs = self::jobs( file_get_contents( $this->config_file ) );
		$this->assertSame( [ "sh -c 'kept'" ], array_column( array_column( $jobs, 'values' ), 'command' ) );
		$this->assertSame( [ 'docker restart services_global-cron_1' ], $this->runner->matching( '/^docker (restart|rm)/' ) );
		$this->assertStringContainsString( "Success: Deleted cron with id $id", $this->stdout() );
	}

	public function test_delete_errors_for_an_unknown_id() {
		$this->assert_ee_error(
			function () {
				$this->command->delete( [ '7' ] );
			},
			'Unable to find cron with id 7'
		);
	}

	/**
	 * CRON-1 (fixed in v2.1.1): deleting the last job removes the scheduler container, which the next create has to boot again.
	 *
	 * @group CRON-1
	 */
	public function test_delete_of_the_last_job_keeps_the_scheduler() {
		$id = $this->insert_cron( 'host', "sh -c 'true'", '@daily' );

		$this->command->delete( [ (string) $id ] );

		$this->assertSame( [], $this->runner->matching( '/^docker rm /' ) );
	}

	public function test_list_shows_only_the_jobs_of_the_site() {
		$this->add_site( 'a.test' );
		$this->add_site( 'b.test' );
		$this->insert_cron( 'a.test', "sh -c 'wp cron event run'", '0 */5 * * * *', 'www-data' );
		$this->insert_cron( 'b.test', "sh -c 'other'", '@daily', 'www-data' );

		$this->assertSame(
			[
				[ 'id', 'site_url', 'user', 'command', 'schedule' ],
				[ '1', 'a.test', 'www-data', "sh -c 'wp cron event run'", '0 */5 * * * *' ],
			],
			$this->listed( [ 'a.test' ] )
		);
	}

	public function test_list_all_shows_every_site() {
		$this->add_site( 'a.test' );
		$this->insert_cron( 'a.test', "sh -c 'one'", '@daily', 'www-data' );
		$this->insert_cron( 'gone.test', "sh -c 'two'", '@daily', 'www-data' );

		$this->assertSame(
			[
				[ 'id', 'site_url', 'user', 'command', 'schedule' ],
				[ '1', 'a.test', 'www-data', "sh -c 'one'", '@daily' ],
				[ '2', 'gone.test', 'www-data', "sh -c 'two'", '@daily' ],
			],
			$this->listed( [], [ 'all' => true ] )
		);
	}

	public function test_list_errors_when_there_are_no_jobs() {
		$this->assert_ee_error(
			function () {
				$this->listed( [ 'host' ] );
			},
			'No cron jobs found.'
		);
	}

	/**
	 * CRON-12 (fixed in v2.1.1): a host job without a user makes the table formatter fail with "Invalid field: user.".
	 *
	 * @group CRON-12
	 */
	public function test_list_shows_host_jobs_without_a_user() {
		$this->insert_cron( 'host', "sh -c 'uptime'", '@hourly' );
		$this->insert_cron( 'host', "sh -c 'date'", '@daily', 'root' );

		$rows = $this->no_ee_error(
			function () {
				return $this->listed( [ 'host' ] );
			}
		);

		$this->assertSame( [ '1', 'host', '', "sh -c 'uptime'", '@hourly' ], $rows[1] );
		$this->assertSame( [ '2', 'host', 'root', "sh -c 'date'", '@daily' ], $rows[2] );
	}

	/**
	 * CRON-10: `list <site>/` passes the site check but queries with the trailing slash.
	 *
	 * @group known-bug
	 * @group CRON-10
	 */
	public function test_list_accepts_a_trailing_slash() {
		$this->add_site( 'a.test' );
		$this->insert_cron( 'a.test', "sh -c 'one'", '@daily', 'www-data' );

		$rows = $this->no_ee_error(
			function () {
				return $this->listed( [ 'a.test/' ] );
			}
		);

		$this->assertSame( [ '1', 'a.test', 'www-data', "sh -c 'one'", '@daily' ], $rows[1] );
	}
}
