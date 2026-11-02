<?php

namespace MediaWiki\Extension\EditorGrowth\Tests;

use MediaWiki\Extension\EditorGrowth\Rest\EventHandler;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\EditorGrowth\Rest\EventHandler
 * @group Database
 */
class EventHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;

	private const ID = '0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( 'EditorGrowthEnabledExperiments', [ 'single-edit-tab-v1' ] );
	}

	private function post( array $body, ?UserIdentity $user = null ): int {
		$services = $this->getServiceContainer();
		$handler = new EventHandler(
			$services->getMainConfig(),
			$services->getConnectionProvider(),
			$services->getActorNormalization(),
			$services->getUserFactory()
		);
		$request = new RequestData( [
			'method' => 'POST',
			'headers' => [ 'Content-Type' => 'application/json' ],
			'bodyContents' => json_encode( $body ),
		] );
		return $this->executeHandler( $handler, $request, [], [], [], [],
			$user ? new UltimateAuthority( $user ) : $this->mockAnonUltimateAuthority()
		)->getStatusCode();
	}

	public function testAnonymousEventsAreFiltered(): void {
		$events = [
			[ 'experiment' => 'single-edit-tab-v1', 'bucket' => 'source-only', 'action' => 'edit_click' ],
			[ 'experiment' => 'single-edit-tab-v1', 'bucket' => 'nope', 'action' => 'edit_click' ],
			[ 'experiment' => 'single-edit-tab-v1', 'bucket' => 'control', 'action' => 'nope' ],
			[ 'experiment' => 'unknown-experiment', 'bucket' => 'control', 'action' => 'edit_click' ],
		];
		$this->assertSame( 204, $this->post( [ 'id' => self::ID, 'events' => $events ] ) );
		$this->newSelectQueryBuilder()
			->select( [ 'ege_identifier', 'ege_bucket', 'ege_action' ] )
			->from( 'editorgrowth_event' )
			->assertResultSet( [ [ self::ID, 'source-only', 'edit_click' ] ] );
		$this->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'editorgrowth_identity' )->assertFieldValue( 0 );
	}

	public static function provideMalformedBody(): iterable {
		$event = [ 'experiment' => 'single-edit-tab-v1', 'bucket' => 'control', 'action' => 'edit_click' ];
		yield 'bad id' => [ [ 'id' => 'ABC' ] ];
		yield 'id with trailing newline' => [ [ 'id' => self::ID . "\n" ] ];
		yield 'body too large' => [ [ 'id' => self::ID, 'events' => array_fill( 0, 1000, [] ) ] ];
		yield 'non-string field' => [ [ 'id' => self::ID, 'events' => [ [ 'experiment' => [] ] + $event ] ] ];
		yield 'missing field' => [ [ 'id' => self::ID, 'events' => [ [ 'bucket' => 'control' ] ] ] ];
		yield 'non-object event' => [ [ 'id' => self::ID, 'events' => [ 'garbage' ] ] ];
		yield 'too many events' => [ [ 'id' => self::ID, 'events' => array_fill( 0, 11, $event ) ] ];
	}

	/** @dataProvider provideMalformedBody */
	public function testMalformedBodyIsRejected( array $body ): void {
		$this->expectException( HttpException::class );
		$this->post( $body );
	}

	public function testRegisteredUsersLinkOnceEachAndStoreNoEvents(): void {
		$user = $this->getTestUser()->getUserIdentity();
		$event = [ 'experiment' => 'single-edit-tab-v1', 'bucket' => 'control', 'action' => 'edit_click' ];
		$this->assertSame( 204, $this->post( [ 'id' => self::ID, 'events' => [ $event ] ], $user ) );
		$this->assertSame( 204, $this->post( [ 'id' => self::ID, 'events' => [] ], $user ) );
		$this->assertSame( 204, $this->post( [ 'id' => self::ID ], $this->getTestSysop()->getUserIdentity() ) );
		$this->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'editorgrowth_identity' )
			->where( [ 'egi_identifier' => self::ID ] )->assertFieldValue( 2 );
		$this->newSelectQueryBuilder()->select( 'COUNT(*)' )->from( 'editorgrowth_event' )->assertFieldValue( 0 );
	}

	public function testRateLimited(): void {
		$this->overrideConfigValue( 'RateLimits', [ 'editorgrowth-event' => [ 'ip' => [ 1, 60 ] ] ] );
		$this->assertSame( 204, $this->post( [ 'id' => self::ID ] ) );
		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 429 );
		$this->post( [ 'id' => self::ID ] );
	}
}
