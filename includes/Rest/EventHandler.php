<?php

namespace MediaWiki\Extension\EditorGrowth\Rest;

use MediaWiki\Config\Config;
use MediaWiki\Extension\EditorGrowth\Experiments;
use MediaWiki\ParamValidator\TypeDef\ArrayDef;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class EventHandler extends SimpleHandler {

	public function __construct(
		private readonly Config $config,
		private readonly IConnectionProvider $dbProvider,
		private readonly ActorNormalization $actorNormalization,
		private readonly UserFactory $userFactory
	) {
	}

	public function run(): Response {
		[
			'id' => $id,
			'events' => $events
		] = $this->getValidatedBody();
		if ( !preg_match( '/^[0-9a-f]{16}\z/', $id ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'paramvalidator-badvalue-enumnotmulti' )->plaintextParams( 'id', $id ), 400
			);
		}
		if ( $this->userFactory->newFromAuthority( $this->getAuthority() )->pingLimiter( 'editorgrowth-event' ) ) {
			throw new LocalizedHttpException( MessageValue::new( 'actionthrottledtext' ), 429 );
		}
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$now = $dbw->timestamp( ConvertibleTimestamp::now() );
		$user = $this->getAuthority()->getUser();

		// Stop tracking logged-in users. Always rely on MW's existing DB tables for subsequent activity.
		// Only need to note the connection between the ID and actor.
		if ( $user->isRegistered() ) {
			$dbw->newInsertQueryBuilder()->insertInto( 'editorgrowth_identity' )->ignore()->row( [
				'egi_identifier' => $id,
				'egi_actor' => $this->actorNormalization->acquireActorId( $user, $dbw ),
				'egi_timestamp' => $now,
			] )->caller( __METHOD__ )->execute();

			return $this->getResponseFactory()->createNoContent();
		}

		$experiments = Experiments::getEnabled( $this->config );
		$rows = [];
		foreach ( $events as [ 'experiment' => $name, 'bucket' => $bucket, 'action' => $action ] ) {
			$spec = $experiments[$name] ?? null;
			if ( isset( $spec['buckets'][$bucket] ) && isset( $spec['actions'][$action] ) ) {
				$rows[] = [
					'ege_experiment' => $name,
					'ege_identifier' => $id,
					'ege_bucket' => $bucket,
					'ege_action' => $action,
					'ege_timestamp' => $now,
				];
			}
		}
		if ( $rows ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'editorgrowth_event' )
				->rows( $rows )
				->caller( __METHOD__ )
				->execute();
		}

		return $this->getResponseFactory()->createNoContent();
	}

	/** @inheritDoc */
	public function getBodyParamSettings(): array {
		return [
			'id' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'events' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_DEFAULT => [],
				// Validate against malformed requests and DoS
				ArrayDef::PARAM_SCHEMA => ArrayDef::makeListSchema( ArrayDef::makeObjectSchema( [
						'experiment' => 'string',
						'bucket' => 'string',
						'action' => 'string',
					] ) ) + [ 'maxItems' => 10 ],
			],
		];
	}
}
