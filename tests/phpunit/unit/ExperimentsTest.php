<?php

namespace MediaWiki\Extension\EditorGrowth\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\EditorGrowth\Experiments;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\EditorGrowth\Experiments
 */
class ExperimentsTest extends MediaWikiUnitTestCase {

	private function getEnabled( array $value ): array {
		return Experiments::getEnabled( new HashConfig( [ 'EditorGrowthEnabledExperiments' => $value ] ) );
	}

	public function testGetEnabled() {
		$enabled = $this->getEnabled( [
			'single-edit-tab-v1' => [],
			'edit-button-prominence-v1' => [ 'styles' => false ],
			'nonexistent' => [],
		] );
		$this->assertSame( [ 'single-edit-tab-v1', 'edit-button-prominence-v1' ], array_keys( $enabled ) );
		$this->assertSame( [], $enabled['single-edit-tab-v1']['options'] );
		$this->assertSame( [ 'styles' => false ], $enabled['edit-button-prominence-v1']['options'] );
	}
}
