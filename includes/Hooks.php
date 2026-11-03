<?php

namespace MediaWiki\Extension\EditorGrowth;

use MediaWiki\Html\Html;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use Wikimedia\Minify\JavaScriptMinifier;
use Wikimedia\ObjectCache\WANObjectCache;

class Hooks implements BeforePageDisplayHook {

	private const MODULES = __DIR__ . '/../modules/';

	public function __construct(
		private readonly WANObjectCache $cache
	) {
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$forced = [];
		foreach ( explode( '|', $out->getRequest()->getText( 'experiment' ) ) as $pair ) {
			[ $name, $bucket ] = explode( ',', $pair, 2 ) + [ '', '' ];
			if ( isset( Experiments::EXPERIMENTS[$name]['buckets'][$bucket] ) ) {
				$forced[$name] = $bucket;
			}
		}
		$experiments = Experiments::getEnabled( $out->getConfig() )
			+ array_intersect_key( Experiments::EXPERIMENTS, $forced );
		if ( !$experiments ) {
			return;
		}
		$experimentString = implode( '', array_keys( $experiments ) );
		$cacheKey = $this->cache->makeKey( 'editorgrowth-js', $experimentString );
		$script = $this->cache->getWithSetCallback(
			$cacheKey,
			WANObjectCache::TTL_HOUR,
			static fn () => self::buildScript( $experiments )
		);
		foreach ( $experiments as $spec ) {
			if ( isset( $spec['styles'] ) ) {
				$out->addModuleStyles( $spec['styles'] );
			}
		}
		if ( $forced ) {
			$script = 'window.mwEditorGrowthForced=' . Html::encodeJsVar( $forced ) . ';' . $script;
		}
		$out->addHeadItem( 'editorgrowth', Html::inlineScript( $script ) );
	}

	private static function buildScript( array $experiments ): string {
		$state = [
			'endpoint' => wfScript( 'rest' ) . '/editorgrowth/v0/event',
			'experiments' => []
		];
		$scripts = [];
		foreach ( $experiments as $name => $spec ) {
			$state['experiments'][$name] = [
				'buckets' => $spec['buckets'],
				'actions' => $spec['actions']
			];
			if ( isset( $spec['script'] ) ) {
				$scripts[] = self::inline( $spec['script'] );
			}
		}
		return implode( ';', [
			'window.mwEditorGrowth=' . Html::encodeJsVar( $state ),
			self::inline( 'editorgrowth.js' ),
			...$scripts
		] );
	}

	private static function inline( string $file ): string {
		return JavaScriptMinifier::minify( file_get_contents( self::MODULES . $file ) );
	}
}
