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
 * BetaFeatures extension hooks
 *
 * @file
 * @ingroup Extensions
 * @copyright 2013 Mark Holmquist and others; see AUTHORS
 * @license GNU General Public License version 2 or later
 */

class BetaFeaturesMissingFieldException extends Exception {
}

class BetaFeaturesHooks {

	// 30 minutes
	const COUNT_CACHE_TTL = 1800;

	private static $features = array();

	/**
	 * @param array $prefs
	 * @return array|mixed
	 */
	private static function getUserCountsFromDb( $prefs ) {
		global $wgMemc;

		$jqg = JobQueueGroup::singleton();

		// If we aren't waiting to update the counts, push a job to do it.
		// Only one job in this queue at a time.
		// Will only get added once every thirty minutes, when the
		// cache is invalidated.
		if ( $jqg->get( 'updateBetaFeaturesUserCounts' )->isEmpty() ) {
			$updateJob = new UpdateBetaFeatureUserCountsJob(
				Title::newMainPage(),
				array(
					'prefs' => $prefs,
				)
			);
			$jqg->push( $updateJob );
		}

		$counts = array();
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select(
			'betafeatures_user_counts',
			array(
				'feature',
				'number',
			),
			array(),
			__METHOD__
		);

		foreach ( $res as $row ) {
			$counts[$row->feature] = $row->number;

			// Cache for 30 minutes
			$key = wfMemcKey( 'betafeatures', 'usercounts', $row->feature );
			$wgMemc->set( $key, $row->number, self::COUNT_CACHE_TTL );
		}

		return $counts;
	}

	/**
	 * @param array $prefs
	 * @return array|mixed
	 */
	static function getUserCounts( $prefs ) {
		global $wgMemc;

		$counts = array();

		foreach ( $prefs as $pref ) {
			$key = wfMemcKey( 'betafeatures', 'usercounts', $pref );
			$count = $wgMemc->get( $key );

			if ( $count === false ) {
				// Stop trying, go update the database
				// TODO better heuristic?
				return self::getUserCountsFromDb( $prefs );
			}

			$counts[$pref] = $count;
		}

		return $counts;
	}

	/**
	 * @param User $user User who's just saved their preferences
	 * @param array &$options List of options
	 */
	static function updateUserCounts( $user, &$options ) {
		global $wgMemc;

		// Let's find out what's changed
		$oldUser = User::newFromName( $user->getName() );
		$betaFeatures = array();
		wfRunHooks( 'GetBetaFeaturePreferences', array( $user, &$betaFeatures ) );

		foreach ( $betaFeatures as $name => $option ) {
			$newVal = $user->getOption( $name );
			$oldVal = $oldUser->getOption( $name );

			if ( $oldVal === $newVal ||
					( $oldVal === null &&
						$newVal === HTMLFeatureField::OPTION_DISABLED ) ) {
				// Nothing changed, carry on
				continue;
			}

			$key = wfMemcKey( 'betafeatures', 'usercounts', $name );

			if ( $newVal === HTMLFeatureField::OPTION_ENABLED ) {
				$wgMemc->incr( $key );
			} else {
				$wgMemc->decr( $key );
			}
		}

		return true;
	}

	/**
	 * @param User $user
	 * @param array $prefs
	 * @return bool
	 * @throws BetaFeaturesMissingFieldException
	 */
	public static function getPreferences( User $user, array &$prefs ) {
		$betaPrefs = array();
		$depHooks = array();

		wfRunHooks( 'GetBetaFeaturePreferences', array( $user, &$betaPrefs ) );

		$prefs['betafeatures-popup-disable'] = array(
			'type' => 'api',
			'default' => 0,
		);

		$prefs['betafeatures-section-desc'] = array(
			'class' => 'HTMLTextBlockField',
			'label' => implode( '', array(
				Html::element(
					'p',
					array(),
					wfMessage( 'betafeatures-section-desc' )->numParams( count( $betaPrefs ) )->text()
				),

				Html::rawElement( 'p', array(), implode( ' | ', array(
					Html::element(
						'a',
						array(
							'href' => '//mediawiki.org/wiki/Special:MyLanguage/About_Beta_Features'
						),
						wfMessage( 'betafeatures-about-betafeatures' )->text()
					),

					Html::element(
						'a',
						array(
							'href' => '//mediawiki.org/wiki/Talk:About_Beta_Features'
						),
						wfMessage( 'betafeatures-discuss-betafeatures' )->text()
					),
				) ) ),
			) ),
			'section' => 'betafeatures',
		);

		$prefs['betafeatures-auto-enroll'] = array(
			'class' => 'NewHTMLCheckField',
			'label-message' => 'betafeatures-auto-enroll',
			'section' => 'betafeatures',
		);

		// Purely visual field.
		$prefs['betafeatures-breaking-hr'] = array(
			'class' => 'HTMLHorizontalRuleField',
			'section' => 'betafeatures',
		);

		$counts = self::getUserCounts( array_keys( $betaPrefs ) );

		// Set up dependency hooks array
		// This complex structure brought to you by Per-Wiki Configuration,
		// coming soon to a wiki very near you.
		wfRunHooks( 'GetBetaFeatureDependencyHooks', array( &$depHooks ) );

		$autoEnrollAll = $user->getOption( 'beta-feature-auto-enroll' ) === HTMLFeatureField::OPTION_ENABLED;
		$autoEnroll = array();

		foreach ( $betaPrefs as $key => $info ) {
			if ( isset( $info['auto-enrollment'] ) ) {
				$autoEnroll[$info['auto-enrollment']] = $key;
			}
		}

		foreach ( $betaPrefs as $key => $info ) {
			if ( isset( $info['dependent'] ) && $info['dependent'] === true ) {
				$success = true;

				if ( isset( $depHooks[$key] ) ) {
					$success = wfRunHooks( $depHooks[$key] );
				}

				if ( $success !== true ) {
					// Skip this preference!
					continue;
				}
			}

			$opt = array(
				'class' => 'HTMLFeatureField',
				'section' => 'betafeatures',
			);

			$requiredFields = array(
				'label-message' => true,
				'desc-message' => true,
				'screenshot' => false,
				'requirements' => false,
				'info-link' => false,
				'info-message' => false,
				'discussion-link' => false,
				'discussion-message' => false,
			);

			foreach ( $requiredFields as $field => $required ) {
				if ( isset( $info[$field] ) ) {
					$opt[$field] = $info[$field];
				} elseif ( $required ) {
					// A required field isn't present in the info array
					// we got from the GetBetaFeaturePreferences hook.
					// Don't add this feature to the form.
					throw new BetaFeaturesMissingFieldException( "The field {$field} was missing from the beta feature {$key}." );
				}
			}

			if ( isset( $counts[$key] ) ) {
				$opt['user-count'] = $counts[$key];
			}

			$prefs[$key] = $opt;

			$currentValue = $user->getOption( $key );

			$autoEnrollForThisPref = false;

			if ( isset( $info['group'] ) && isset( $autoEnroll[$info['group']] ) ) {
				$autoEnrollForThisPref = $user->getOption( $autoEnroll[$info['group']] ) === HTMLFeatureField::OPTION_ENABLED;
			}

			$autoEnrollHere = $autoEnrollAll === true || $autoEnrollForThisPref === true;

			if ( $currentValue !== HTMLFeatureField::OPTION_ENABLED &&
					$currentValue !== HTMLFeatureField::OPTION_DISABLED &&
					$autoEnrollHere === true ) {
				// We haven't seen this before, and the user has auto-enroll enabled!
				// Set the option to true.
				$user->setOption( $key, HTMLFeatureField::OPTION_ENABLED );
			}
		}

		foreach ( $betaPrefs as $key => $info ) {
			$features = array();

			if ( isset( $prefs[$key]['requirements'] ) ) {

				// Check which other beta features are required, and fetch their labels
				if ( isset( $prefs[$key]['requirements']['betafeatures'] ) ) {
					$requiredPrefs = array();
					foreach( $prefs[$key]['requirements']['betafeatures'] as $preference ) {
						if ( !$user->getOption( $preference ) ) {
							$requiredPrefs[] = $prefs[$preference]['label-message'];
						}
					}
					if ( count( $requiredPrefs ) ) {
						$prefs[$key]['requirements']['betafeatures-messages'] = $requiredPrefs;
					}
				}

				// If a browser blacklist is supplied, store so it can be passed as JSON
				if ( isset( $prefs[$key]['requirements']['blacklist'] ) ) {
					$features['blacklist'] = $prefs[$key]['requirements']['blacklist'];
				}

				// Test skin support
				if (
					isset( $prefs[$key]['requirements']['skins'] ) &&
					!in_array( RequestContext::getMain()->getSkin()->getSkinName(), $prefs[$key]['requirements']['skins'] )
				) {
					$prefs[$key]['requirements']['skin-not-supported'] = true;
				}
			}
			self::$features[$key] = !empty( $features ) ? $features : null;
		}

		$user->saveSettings();

		return true;
	}

	public static function onMakeGlobalVariablesScript( array &$vars ) {
		$vars['wgBetaFeaturesFeatures'] = self::$features;

		return true;
	}

	/**
	 * @param array $personal_urls
	 * @param Title $title
	 * @param SkinTemplate $skintemplate
	 * @return bool
	 */
	static function getBetaFeaturesLink( &$personal_urls, Title $title, SkinTemplate $skintemplate ) {
		$user = $skintemplate->getUser();
		if ( $user->isLoggedIn() ) {
			$personal_urls = wfArrayInsertAfter( $personal_urls, array(
				'betafeatures' => array(
					'text' => wfMessage( 'betafeatures-toplink' )->text(),
					'href' => SpecialPage::getTitleFor( 'Preferences', false, 'mw-prefsection-betafeatures' )->getLinkURL(),
					'active' => $title->isSpecial( 'Preferences' ),
				),
			), 'preferences' );

			if ( !$user->getOption( 'betafeatures-popup-disable' ) ) {
				$skintemplate->getOutput()->addModules( 'ext.betaFeatures.popup' );
			}
		}

		return true;
	}

	/**
	 * @param array $files
	 * @return bool
	 */
	static function getUnitTestsList( &$files ) {
		$testDir = __DIR__ . '/tests';
		$files = array_merge( $files, glob( "$testDir/*Test.php" ) );
		return true;
	}

	/**
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	static function getSchemaUpdates( $updater ) {
		$updater->addExtensionTable( 'betafeatures_user_counts',
			__DIR__ . '/sql/create_counts.sql' );
		return true;
	}

	public static function onExtensionTypes( array &$extTypes ) {
		$extTypes['betafeatures'] = wfMessage( 'betafeatures-extension-type' )->escaped();
		return true;
	}

}
