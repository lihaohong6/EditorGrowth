<?php

namespace MediaWiki\Extension\EditorGrowth;

use MediaWiki\Config\Config;

class Experiments {

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
				'edit_click' => '#ca-edit a, #ca-ve-edit a, #ca-edit-sticky-header, #ca-ve-edit-sticky-header, '
					. '#ca-more-edit a, #ca-more-ve-edit a',
			],
		],
	];

	/** @return array<string,array> */
	public static function getEnabled( Config $config ): array {
		return array_intersect_key(
			self::EXPERIMENTS,
			array_flip( $config->get( 'EditorGrowthEnabledExperiments' ) )
		);
	}
}
