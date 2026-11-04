<?php

namespace MediaWiki\Extension\EditorGrowth\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @ingroup Maintenance
 */
class ExportData extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Export raw EditorGrowth tables as JSON, along with the users linked to them.' );
		$this->addOption( 'experiment', 'Only export events of this experiment', false, true );
		$this->requireExtension( 'EditorGrowth' );
		$this->setBatchSize( 1000 );
	}

	public function execute(): void {
		$events = $this->getEvents( $this->getOption( 'experiment' ) );
		$identities = $this->getIdentities( array_unique( array_column( $events, 'ege_identifier' ) ) );
		$users = $this->getUsers( array_unique( array_column( $identities, 'egi_actor' ) ) );
		$this->output(
			json_encode( [
				'events' => $events,
				'identities' => $identities,
				'users' => $users,
			], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . "\n"
		);
	}

	private function getEvents( ?string $experiment ): array {
		$dbr = $this->getReplicaDB();
		$query = $dbr->newSelectQueryBuilder()->select(
				[
					'ege_id',
					'ege_experiment',
					'ege_identifier',
					'ege_bucket',
					'ege_action',
					'ege_timestamp'
				]
			)->from( 'editorgrowth_event' )->orderBy( 'ege_id' )->caller( __METHOD__ );
		if ( $experiment !== null ) {
			$query->where( [ 'ege_experiment' => $experiment ] );
		}
		$events = [];
		foreach ( $query->fetchResultSet() as $row ) {
			$events[] = [
				'ege_id' => (int)$row->ege_id,
				'ege_experiment' => $row->ege_experiment,
				'ege_identifier' => $row->ege_identifier,
				'ege_bucket' => $row->ege_bucket,
				'ege_action' => $row->ege_action,
				'ege_timestamp' => self::iso( $row->ege_timestamp ),
			];
		}

		return $events;
	}

	/**
	 * @param string[] $identifiers
	 */
	private function getIdentities( array $identifiers ): array {
		$identities = [];
		foreach ( array_chunk( $identifiers, $this->getBatchSize() ) as $batch ) {
			$rows = $this->getReplicaDB()
						 ->newSelectQueryBuilder()
						 ->select(
							 [
								 'egi_identifier',
								 'egi_actor',
								 'egi_timestamp'
							 ]
						 )
						 ->from( 'editorgrowth_identity' )
						 ->where( [ 'egi_identifier' => array_map( 'strval', $batch ) ] )
						 ->caller( __METHOD__ )
						 ->fetchResultSet();
			foreach ( $rows as $row ) {
				$identities[] = [
					'egi_identifier' => $row->egi_identifier,
					'egi_actor' => (int)$row->egi_actor,
					'egi_timestamp' => self::iso( $row->egi_timestamp ),
				];
			}
		}

		return $identities;
	}

	/**
	 * @param int[] $actors
	 */
	private function getUsers( array $actors ): array {
		$dbr = $this->getReplicaDB();
		$tempUserConfig = $this->getServiceContainer()->getTempUserConfig();
		$users = [];
		foreach ( array_chunk( $actors, $this->getBatchSize() ) as $batch ) {
			$edits = [];
			$editRows = $dbr->newSelectQueryBuilder()
							->select(
								[
									'rev_actor',
									'first' => 'MIN(rev_timestamp)',
									'last' => 'MAX(rev_timestamp)'
								]
							)
							->from( 'revision' )
							->where( [ 'rev_actor' => $batch ] )
							->groupBy( 'rev_actor' )
							->caller( __METHOD__ )
							->fetchResultSet();
			foreach ( $editRows as $row ) {
				$edits[$row->rev_actor] = $row;
			}
			$rows = $dbr->newSelectQueryBuilder()
						->select(
							[
								'actor_id',
								'user_id',
								'user_name',
								'user_registration'
							]
						)
						->from( 'actor' )
						->join( 'user', null, 'user_id = actor_user' )
						->where( [ 'actor_id' => $batch ] )
						->caller( __METHOD__ )
						->fetchResultSet();
			foreach ( $rows as $row ) {
				$users[] = [
					'actor_id' => (int)$row->actor_id,
					'user_id' => (int)$row->user_id,
					'user_name' => $row->user_name,
					'user_registration' => self::iso( $row->user_registration ),
					'temp_account' => $tempUserConfig->isTempName( $row->user_name ),
					'first_edit' => self::iso( $edits[$row->actor_id]->first ?? null ),
					'last_edit' => self::iso( $edits[$row->actor_id]->last ?? null ),
				];
			}
		}

		return $users;
	}

	private static function iso( ?string $timestamp ): ?string {
		return $timestamp === null ? null : ConvertibleTimestamp::convert( TS_ISO_8601, $timestamp );
	}
}

// @codeCoverageIgnoreStart
return ExportData::class;
// @codeCoverageIgnoreEnd
