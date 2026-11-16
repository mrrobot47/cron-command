<?php

use EE\Migration\CleanupOldEntries;
use EE\Migration\NoOverlapMigration;
use EE\Migration\RecreateCrons;
use EE\Migration\UpdateCronUser;
use EE\Model\Option;

foreach ( [ 'no_overlap_migration', 'update_cron_user', 'cleanup_old_entries', 'recreate_crons' ] as $ee_cron_migration ) {
	require_once current( glob( dirname( __DIR__, 2 ) . "/migrations/db/*_cron-command_$ee_cron_migration.php" ) );
}
unset( $ee_cron_migration );

/**
 * cron-command DB migrations: their skip conditions and data transforms on the fixture DB.
 */
class CronMigrationsTest extends CronTestCase {

	/** Pre-no-overlap config the no-overlap migration backs up. */
	private $legacy_config;

	protected function setUp(): void {
		parent::setUp();
		$this->legacy_config = EE_ROOT_DIR . '/services/cron/config.ini';
		file_put_contents( $this->legacy_config, "[job-local \"old\"]\nschedule = @daily\n" );
		exec( 'rm -rf ' . escapeshellarg( EE_BACKUP_DIR . '/cron' ) );
	}

	/** An upgrade (not a fresh install): the DB has a version. */
	private function upgrading() {
		Option::set( 'version', '4.12.0' );
	}

	public function migrations() {
		return [
			'no-overlap'  => [ NoOverlapMigration::class ],
			'cron user'   => [ UpdateCronUser::class ],
			'cleanup'     => [ CleanupOldEntries::class ],
			'recreate'    => [ RecreateCrons::class ],
		];
	}

	/**
	 * Nothing to migrate on a fresh install: the rows, the config and the scheduler are left alone.
	 *
	 * @dataProvider migrations
	 */
	public function test_skipped_on_a_fresh_install( $class ) {
		$this->add_site( 'a.test' );
		$this->insert_cron( 'a.test', "sh -c 'x'", '0 0 * * * * *' );
		$rows = self::cron_rows();

		( new $class() )->up();

		$this->assertSame( $rows, self::cron_rows() );
		$this->assertFileDoesNotExist( $this->config_file );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function schedule_transforms() {
		return [
			'seven fields, leading 0'       => [ '0 0 */5 * * * *', '0 */5 * * * *' ],
			'extra spaces'                  => [ '0  0 1 * * *  *', '0 1 * * * *' ],
			'seven fields, leading non-0'   => [ '5 0 * * * * *', '5 0 * * * * *' ],
			'six fields'                    => [ '0 */5 * * * *', '0 */5 * * * *' ],
			'helper'                        => [ '@every 5m', '@every 5m' ],
		];
	}

	/**
	 * Schedules that got the seconds field twice (seven fields starting with 0) lose one; everything else is kept.
	 *
	 * @dataProvider schedule_transforms
	 */
	public function test_no_overlap_fixes_doubled_seconds_fields( $stored, $migrated ) {
		$this->upgrading();
		$this->insert_cron( 'host', "sh -c 'x'", $stored );

		( new NoOverlapMigration() )->up();

		$this->assertSame( $migrated, self::cron_rows()[0]['schedule'] );
	}

	public function test_no_overlap_backs_up_the_old_config_and_regenerates_it() {
		$this->upgrading();
		$this->insert_cron( 'host', "sh -c 'x'", '0 0 */5 * * * *' );

		( new NoOverlapMigration() )->up();

		$this->assertSame( "[job-local \"old\"]\nschedule = @daily\n", file_get_contents( EE_BACKUP_DIR . '/cron/config.ini.pre-no-overlap.bak' ) );
		$jobs = self::jobs( file_get_contents( $this->config_file ) );
		$this->assertSame( [ '0 */5 * * * *' ], array_column( array_column( $jobs, 'values' ), 'schedule' ) );
		$this->assertSame( [ 'docker restart services_global-cron_1' ], $this->runner->commands() );
	}

	public function test_no_overlap_is_skipped_without_cron_jobs() {
		$this->upgrading();

		( new NoOverlapMigration() )->up();

		$this->assertFileDoesNotExist( EE_BACKUP_DIR . '/cron/config.ini.pre-no-overlap.bak' );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_update_cron_user_sets_www_data_on_site_jobs_without_a_user() {
		$this->upgrading();
		$this->add_site( 'a.test' );
		$this->insert_cron( 'a.test', "sh -c 'one'", '@daily' );
		$this->insert_cron( 'a.test', "sh -c 'two'", '@daily', 'root' );

		( new UpdateCronUser() )->up();

		$this->assertSame( [ 'www-data', 'root' ], array_column( self::cron_rows(), 'user' ) );
		$this->assertSame( [ 'www-data', 'root' ], array_column( array_column( self::jobs( file_get_contents( $this->config_file ) ), 'values' ), 'user' ) );
		$this->assertSame( [ 'docker restart services_global-cron_1' ], $this->runner->matching( '/^docker restart/' ) );
	}

	/**
	 * CRON-4 (fixed in v2.1.1): the migration gave host jobs `www-data` too; the regenerated config must still not put it in a job-local section.
	 *
	 * @group CRON-4
	 */
	public function test_update_cron_user_leaves_host_jobs_runnable() {
		$this->upgrading();
		$this->add_site( 'a.test' );
		$this->insert_cron( 'host', "sh -c 'uptime'", '@daily' );

		( new UpdateCronUser() )->up();

		$jobs = self::jobs( file_get_contents( $this->config_file ) );
		$this->assertSame( 'job-local', $jobs[0]['type'] );
		$this->assertArrayNotHasKey( 'user', $jobs[0]['values'] );
	}

	public function test_update_cron_user_is_skipped_without_sites() {
		$this->upgrading();
		$this->insert_cron( 'host', "sh -c 'uptime'", '@daily' );

		( new UpdateCronUser() )->up();

		$this->assertNull( self::cron_rows()[0]['user'] );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_cleanup_removes_jobs_of_deleted_sites() {
		$this->upgrading();
		$this->add_site( 'a.test' );
		$this->insert_cron( 'a.test', "sh -c 'kept'", '@daily', 'www-data' );
		$this->insert_cron( 'gone.test', "sh -c 'gone'", '@daily', 'www-data' );

		( new CleanupOldEntries() )->up();

		$this->assertSame( [ 'a.test' ], array_column( self::cron_rows(), 'site_url' ) );
		$this->assertSame( [ "sh -c 'kept'" ], array_column( array_column( self::jobs( file_get_contents( $this->config_file ) ), 'values' ), 'command' ) );
	}

	/**
	 * CRON-16: host jobs have no site row, so the cleanup deletes every host job.
	 *
	 * @group known-bug
	 * @group CRON-16
	 */
	public function test_cleanup_keeps_host_jobs() {
		$this->upgrading();
		$this->add_site( 'a.test' );
		$this->insert_cron( 'host', "sh -c 'uptime'", '@daily' );

		( new CleanupOldEntries() )->up();

		$this->assertSame( [ 'host' ], array_column( self::cron_rows(), 'site_url' ) );
	}

	public function test_recreate_regenerates_the_config_when_there_are_sites_and_jobs() {
		$this->upgrading();
		$this->add_site( 'a.test' );
		$this->insert_cron( 'a.test', "sh -c 'one'", '@daily', 'www-data' );

		( new RecreateCrons() )->up();

		$this->assertCount( 1, self::jobs( file_get_contents( $this->config_file ) ) );
		$this->assertSame( [ 'docker restart services_global-cron_1' ], $this->runner->matching( '/^docker restart/' ) );
	}

	public function test_recreate_is_skipped_without_jobs_or_sites() {
		$this->upgrading();
		$this->add_site( 'a.test' );
		( new RecreateCrons() )->up();

		EE\Tests\TestDB::pdo()->exec( 'DELETE FROM sites' );
		$this->insert_cron( 'host', "sh -c 'uptime'", '@daily' );
		( new RecreateCrons() )->up();

		$this->assertFileDoesNotExist( $this->config_file );
		$this->assertSame( [], $this->runner->commands() );
	}
}
