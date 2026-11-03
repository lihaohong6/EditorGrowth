<?php

namespace MediaWiki\Extension\EditorGrowth;

use MediaWiki\Config\Config;

class Experiments {

	private const EDIT_CLICK = '#ca-edit a, #ca-ve-edit a, #ca-edit-sticky-header, #ca-ve-edit-sticky-header, '
		. '#ca-more-edit a, #ca-more-ve-edit a';

	public const array EXPERIMENTS = [
		'single-edit-tab-v1' => [
			'buckets' => [
				'control' => 1,
				'source-only' => 1,
				'visual-only' => 1
			],
			'styles' => 'ext.editorGrowth.singleEditTab',
			'script' => 'experiments/single_edit_tab.js',
			'actions' => [
				'edit_click' => self::EDIT_CLICK,
			],
		],
		'edit-button-prominence-v1' => [
			'buckets' => [
				'control' => 1,
				'prominent' => 1
			],
			'styles' => 'ext.editorGrowth.editButtonProminence',
			'actions' => [
				'edit_click' => self::EDIT_CLICK,
			],
		],
	];

	/** @return array<string,array> */
	public static function getEnabled( Config $config ): array {
		$enabled = [];
		foreach ( $config->get( 'EditorGrowthEnabledExperiments' ) as $name => $options ) {
			if ( isset( self::EXPERIMENTS[$name] ) ) {
				$spec = self::EXPERIMENTS[$name];
				$spec['options'] = $options + ( $spec['options'] ?? [] );
				$enabled[$name] = $spec;
			}
		}
		return $enabled;
	}
}
