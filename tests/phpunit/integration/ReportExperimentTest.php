<?php

namespace MediaWiki\Extension\EditorGrowth\Tests;

use MediaWiki\Extension\EditorGrowth\Maintenance\ReportExperiment;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\Tests\User\TempUser\TempUserTestTrait;
use MediaWiki\User\UserIdentity;

/**
 * @covers \MediaWiki\Extension\EditorGrowth\Maintenance\ReportExperiment
 * @group Database
 */
class ReportExperimentTest extends MaintenanceBaseTestCase {
	use TempUserTestTrait;

	private const EXPERIMENT = 'single-edit-tab-v1';
	private const T0 = '20260101000000';

	protected function getMaintenanceClass() {
		return ReportExperiment::class;
	}

	private function event( string $id, string $bucket, string $action = 'edit_click' ): void {
		$this->getDb()->newInsertQueryBuilder()->insertInto( 'editorgrowth_event' )->row( [
			'ege_experiment' => self::EXPERIMENT,
			'ege_identifier' => $id,
			'ege_bucket' => $bucket,
			'ege_action' => $action,
			'ege_timestamp' => $this->getDb()->timestamp( self::T0 ),
		] )->caller( __METHOD__ )->execute();
	}

	private function link( string $id, UserIdentity $user, string $registration ): void {
		$db = $this->getDb();
		$db->newUpdateQueryBuilder()->update( 'user' )
			->set( [ 'user_registration' => $db->timestamp( $registration ) ] )
			->where( [ 'user_id' => $user->getId() ] )
			->caller( __METHOD__ )->execute();
		$db->newInsertQueryBuilder()->insertInto( 'editorgrowth_identity' )->row( [
			'egi_identifier' => $id,
			'egi_actor' => $this->getServiceContainer()->getActorNormalization()->acquireActorId( $user, $db ),
			'egi_timestamp' => $db->timestamp( $registration ),
		] )->caller( __METHOD__ )->execute();
	}

	public function testReport(): void {
		$this->enableAutoCreateTempUser();
		$temp = $this->getServiceContainer()->getTempUserCreator()->create( null, new FauxRequest() )->getUser();
		$tempOnly = $this->getServiceContainer()->getTempUserCreator()->create( null, new FauxRequest() )->getUser();
		$converted = $this->getMutableTestUser()->getUser();
		$editor = $this->getMutableTestUser()->getUser();
		$existing = $this->getMutableTestUser()->getUser();

		$this->event( 'a000000000000000', 'control' );
		$this->link( 'a000000000000000', $temp, '20260102000000' );
		$this->link( 'a000000000000000', $converted, '20260105000000' );
		$this->event( 'b000000000000000', 'control' );
		$this->link( 'b000000000000000', $editor, '20260102000000' );
		$this->event( 'c000000000000000', 'source-only' );
		$this->link( 'c000000000000000', $existing, '20250101000000' );
		$this->event( 'd000000000000000', 'visual-only' );
		$this->event( 'e000000000000000', 'control' );
		$this->event( 'e000000000000000', 'visual-only' );
		$this->link( 'e000000000000000', $this->getMutableTestUser()->getUser(), '20260102000000' );
		$this->event( 'f000000000000000', 'visual-only' );
		$this->link( 'f000000000000000', $tempOnly, '20260102000000' );

		$this->editPage( 'EG2 converted', 'one', '', NS_MAIN, $temp );
		$this->editPage( 'EG2 converted', 'two', '', NS_MAIN, $converted );
		$this->editPage( 'EG2 editor', 'one', '', NS_MAIN, $editor );

		$this->maintenance->loadWithArgv( [ '--experiment', self::EXPERIMENT, '--json' ] );
		$this->maintenance->execute();
		$report = json_decode( $this->getActualOutputForAssertion(), true );

		$this->assertSame( [
			'control' => [ 'edit_click' => 2, 'temp_account' => 1, 'new_account' => 2, 'edit_made' => 2 ],
			'source-only' => [ 'edit_click' => 0, 'temp_account' => 0, 'new_account' => 0, 'edit_made' => 0 ],
			'visual-only' => [ 'edit_click' => 2, 'temp_account' => 1, 'new_account' => 0, 'edit_made' => 0 ],
		], $report['buckets'] );
	}
}
