<?php

namespace MediaWiki\Extension\EditorGrowth;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable(
			'editorgrowth_event',
			dirname( __DIR__ ) . "/sql/{$updater->getDB()->getType()}/tables-generated.sql"
		);
	}
}
