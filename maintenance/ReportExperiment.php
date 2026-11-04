<?php

namespace MediaWiki\Extension\EditorGrowth\Maintenance;

use MediaWiki\Extension\EditorGrowth\Experiments;
use MediaWiki\Maintenance\Maintenance;
use stdClass;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @ingroup Maintenance
 */
class ReportExperiment extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Report results for one EditorGrowth experiment, enabled or not.' );
		$this->addOption( 'experiment', 'Experiment name', true, true );
		$this->addOption( 'json', 'Print the report as JSON, for adding up several wikis' );
		$this->requireExtension( 'EditorGrowth' );
		$this->setBatchSize( 500 );
	}

	public function execute(): void {
		$name = $this->getOption( 'experiment' );
		$spec = Experiments::EXPERIMENTS[$name] ?? $this->fatalError( "No experiment named '$name' is declared." );
		$columns = [
			...array_keys( $spec['actions'] ),
			'temp_account',
			'new_account',
			'edit_made'
		];
		// 2d array: rows are buckets and columns are counts of data we care about
		$report = array_fill_keys( array_keys( $spec['buckets'] ), array_fill_keys( $columns, 0 ) );

		$allIds = $this->getBucketPerId( $name );
		$actions = $this->getActionsPerId( $name );
		$accountInfo = $this->getAccountInformation( $allIds );
		foreach ( $allIds as $id => $eventRow ) {
			$bucket = $eventRow->bucket;
			$accountTypes = $accountInfo[$id] ?? [];
			if ( isset( $accountTypes['existing_account'] ) ) {
				continue;
			}
			foreach ( array_keys( $actions[$id] + $accountTypes ) as $column ) {
				if ( isset( $report[$bucket][$column] ) ) {
					$report[$bucket][$column]++;
				}
			}
		}

		if ( $this->hasOption( 'json' ) ) {
			$this->output( json_encode( [
				'experiment' => $name,
				'buckets' => $report,
			], JSON_PRETTY_PRINT ) . "\n" );
			return;
		}
		$this->output( self::formatRow( 'bucket', $columns ) );
		foreach ( $report as $bucket => $counts ) {
			$this->output( self::formatRow( $bucket, $counts ) );
		}
	}

	/**
	 * @return stdClass[] Collapse multiple events per ID into one event per ID (only counting earliest)
	 */
	private function getBucketPerId( string $name ): array {
		$rows = $this->getReplicaDB()->newSelectQueryBuilder()
			->select( [
				'ege_identifier',
				'bucket' => 'MIN(ege_bucket)',
				'first' => 'MIN(ege_timestamp)',
			] )
			->from( 'editorgrowth_event' )
			->where( [ 'ege_experiment' => $name ] )
			->groupBy( 'ege_identifier' )
			// IDK how but it might happen for whatever reason
			->having( 'COUNT(DISTINCT ege_bucket) = 1' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$result = [];
		foreach ( $rows as $row ) {
			$result[$row->ege_identifier] = $row;
		}
		return $result;
	}

	/**
	 * @return true[][] Actions each browser identifier performed, as keys
	 */
	private function getActionsPerId( string $name ): array {
		$rows = $this->getReplicaDB()->newSelectQueryBuilder()
			->select( [ 'ege_identifier', 'ege_action' ] )
			->distinct()
			->from( 'editorgrowth_event' )
			->where( [ 'ege_experiment' => $name ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$actions = [];
		foreach ( $rows as $row ) {
			$actions[$row->ege_identifier][$row->ege_action] = true;
		}
		return $actions;
	}

	/**
	 * @param stdClass[] $idToRow
	 *
	 * @return true[][] Account kinds each browser identifier was linked to, and whether an edit is made, as keys
	 */
	private function getAccountInformation( array $idToRow ): array {
		$dbr = $this->getReplicaDB();
		$tempUserConfig = $this->getServiceContainer()->getTempUserConfig();
		$accounts = [];
		foreach ( array_chunk( array_keys( $idToRow ), $this->getBatchSize() ) as $ids ) {
			$rows = $dbr->newSelectQueryBuilder()
				->select( [ 'egi_identifier', 'egi_actor', 'user_name', 'user_registration' ] )
				->from( 'editorgrowth_identity' )
				->join( 'actor', null, 'actor_id = egi_actor' )
				->join( 'user', null, 'user_id = actor_user' )
				->where( [ 'egi_identifier' => array_map( 'strval', $ids ) ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			$actors = [];
			foreach ( $rows as $row ) {
				$actors[] = $row->egi_actor;
			}
			$edited = $actors ? array_flip( $dbr->newSelectQueryBuilder()
				->select( 'rev_actor' )
				->distinct()
				->from( 'revision' )
				->where( [ 'rev_actor' => $actors ] )
				->caller( __METHOD__ )
				->fetchFieldValues() ) : [];
			foreach ( $rows as $row ) {
				$id = $row->egi_identifier;
				$kind = match ( true ) {
					$tempUserConfig->isTempName( $row->user_name ) => 'temp_account',
					// Account registration happens after first click -> not someone with an existing account
					self::unix( $row->user_registration ) >= self::unix( $idToRow[$id]->first ) => 'new_account',
					default => 'existing_account',
				};
				$accounts[$id][$kind] = true;
				if ( isset( $edited[$row->egi_actor] ) ) {
					$accounts[$id]['edit_made'] = true;
				}
			}
		}
		return $accounts;
	}

	private static function formatRow( string $label, array $cells ): string {
		$row = sprintf( '%-16s', $label );
		foreach ( $cells as $cell ) {
			$row .= sprintf( '%18s', $cell );
		}
		return $row . "\n";
	}

	private static function unix( ?string $timestamp ): int {
		return $timestamp === null ? 0 : ( new ConvertibleTimestamp( $timestamp ) )->timestamp->getTimestamp();
	}
}

// @codeCoverageIgnoreStart
return ReportExperiment::class;
// @codeCoverageIgnoreEnd
