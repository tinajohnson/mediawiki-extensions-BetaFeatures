<?php
/*
 * This file is part of the MediaWiki extension BetaFeatures.
 *
 * BetaFeatures is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * BetaFeatures is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with BetaFeatures.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Auto enrollment tests
 *
 * @file
 * @ingroup Extensions
 * @copyright 2013 Mark Holmquist and others; see AUTHORS
 * @license GNU General Public License version 2 or later
 */

class AutoEnrollmentTest extends MediaWikiTestCase {

	// Structure of testing preference
	static $testPrefs = array(
		'unittest-all' => array(
			'label-message' => 'nullish',
			'desc-message' => 'nullish',
			'info-link' => 'https://mediawiki.org/wiki/Extension:BetaFeatures',
			'discussion-link' => 'https://mediawiki.org/wiki/Extension_talk:BetaFeatures',
			'auto-enrollment' => 'unittest',
		),

		'unittest-ft1' => array(
			'label-message' => 'something else',
			'desc-message' => 'something even differenter',
			'info-link' => 'https://mediawiki.org/wiki/Extension:BetaFeatures/Auto-enrollment',
			'discussion-link' => 'https://mediawiki.org/wiki/Extension_talk:BetaFeatures/Auto-enrollment',
			'group' => 'unittest',
			'auto-enrollment' => 'unittest2',
		),

		'unittest-ft2' => array(
			'label-message' => 'something even more else',
			'desc-message' => 'something even more differenter',
			'info-link' => 'https://mediawiki.org/wiki/Extension:BetaFeatures/Auto-enrollment2',
			'discussion-link' => 'https://mediawiki.org/wiki/Extension_talk:BetaFeatures/Auto-enrollment2',
			'group' => 'unittest2',
		),
	);

	static function hookThatRegistersPreference( $user, &$betaPrefs ) {
		foreach ( self::$testPrefs as $key => $testPref ) {
			$betaPrefs[$key] = $testPref;
		}

		return true;
	}

	static function getTestData() {
		return array(
			array(
				null,
				null,
				'unittest-ft1',
				null,
				'Hooks set the preference though auto-enroll was not set.'
			),

			array(
				'beta-feature-auto-enroll',
				HTMLFeatureField::OPTION_ENABLED,
				'unittest-ft1',
				HTMLFeatureField::OPTION_ENABLED,
				'Hooks did not set the preference though global auto-enroll was set.',
			),

			array(
				null,
				null,
				'unittest-ft1',
				null,
				'Hooks set the preference though group auto-enroll was not set.'
			),

			array(
				'unittest-all',
				HTMLFeatureField::OPTION_ENABLED,
				'unittest-ft1',
				HTMLFeatureField::OPTION_ENABLED,
				'Hooks did not set the preference though group auto-enroll was set.',
			),

			array(
				null,
				null,
				'unittest-ft2',
				null,
				'Hooks set the preference though no auto-enroll was set.'
			),

			array(
				'unittest-all',
				HTMLFeatureField::OPTION_ENABLED,
				'unittest-ft2',
				HTMLFeatureField::OPTION_ENABLED,
				'Hooks did not set the preference though grandparent group auto-enroll was set.',
			),

			array(
				'beta-feature-auto-enroll',
				HTMLFeatureField::OPTION_ENABLED,
				'unittest-ft2',
				HTMLFeatureField::OPTION_ENABLED,
				'Hooks did not set the preference though global auto-enroll was set.',
			),
		);
	}

	protected function setUp() {
		global $wgHooks;

		parent::setUp();

		$wgHooks['GetBetaFeaturePreferences'] = array( 'AutoEnrollmentTest::hookThatRegistersPreference' );

		$this->user = new User;
		$this->user->addGroup( 'unittesters' );
	}

	/**
	 * @dataProvider getTestData
	 */
	public function testAutoEnroll( $set, $setVal, $check, $expected, $msg ) {
		$user = $this->user;
		$prefs = array();

		if ( $set !== null ) {
			$user->setOption( $set, $setVal );
		}

		wfRunHooks( 'GetPreferences', array( $user, &$prefs ) );

		$value = $user->getOption( $check );
		if ( $expected === null ) {
			$this->assertNull( $value, $msg );
		} else {
			$this->assertEquals( $expected, $value, $msg );
		}
	}
}
