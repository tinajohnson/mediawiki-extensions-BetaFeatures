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
 * @file
 * @ingroup extensions
 * @author Mark Holmquist <mtraceur@member.fsf.org>
 * @copyright Copyright © 2013, Mark Holmquist
 */

class NewHTMLCheckField extends HTMLFormField {

	// Protected internal methods for getting the bits of the field
	// Override these in subclasses (see HTMLFeatureField, e.g.)
	protected function getCheckboxHTML( $value, $attr ) {
		if ( !empty( $this->mParams['invert'] ) ) {
			$value = !$value;
		}

		if ( $attr === null ) {
			$attr = $this->getTooltipAndAccessKey();
		}

		$attr['id'] = $this->mID;

		$classes = array();

		if ( array_key_exists( 'class', $attr ) ) {
			$classes[] = $attr['class'];
		}

		if ( array_key_exists( 'disabled', $this->mParams ) && $this->mParams['disabled'] === true ) {
			$attr['disabled'] = 'disabled';
		}

		if ( $this->mClass !== '' ) {
			$classes[] = $this->mClass;
		}

		$classes[] = 'mw-ui-checkbox';

		$attr['class'] = implode( ' ', $classes );

		return Xml::check( $this->mName, $value, $attr ) . '&#160;';
	}

	protected function getPreCheckboxLabelHTML( $value ) {
		if ( !empty( $this->mParams['invert'] ) ) {
			$value = !$value;
		}

		$labelClasses = array( 'mw-ui-styled-checkbox-label' );
		$labelAttrs = array( 'for' => $this->mID );

		if ( array_key_exists( 'disabled', $this->mParams ) && $this->mParams['disabled'] === true ) {
			$labelClasses[] = 'mw-ui-disabled';
		}

		if ( $value ) {
			$labelClasses[] = 'mw-ui-checked';
		}

		$labelAttrs['class'] = $labelClasses;

		$this->mParent->getOutput()->addModules( 'ext.betaFeatures' );
		return Html::openElement( 'label', $labelAttrs );
	}

	protected function getPostCheckboxLabelHTML() {
		$html = '';

		$html .= Html::closeElement( 'label' );

		$html .= Html::rawElement( 'label', array( 'for' => $this->mID, 'class' => 'mw-ui-text-check-label' ), $this->mLabel );

		return $html;
	}

	function getInputHTML( $value, $attr = null ) {
		return $this->getPreCheckboxLabelHTML( $value, $attr ) .
			$this->getCheckboxHTML( $value, $attr ) .
			$this->getPostCheckboxLabelHTML();
	}

	/**
	 * For a checkbox, the label goes on the right hand side, and is
	 * added in getInputHTML(), rather than HTMLFormField::getRow()
	 * @return String
	 */
	function getLabel() {
		return '&#160;';
	}

	/**
	 * @param  $request WebRequest
	 * @return String
	 */
	function loadDataFromRequest( $request ) {
		$invert = false;
		if ( isset( $this->mParams['invert'] ) && $this->mParams['invert'] ) {
			$invert = true;
		}

		// GetCheck won't work like we want for checks.
		// Fetch the value in either one of the two following case:
		// - we have a valid token (form got posted or GET forged by the user)
		// - checkbox name has a value (false or true), ie is not null
		if ( $request->getCheck( 'wpEditToken' ) || $request->getVal( $this->mName ) !== null ) {
			// XOR has the following truth table, which is what we want
			// INVERT VALUE | OUTPUT
			// true   true  | false
			// false  true  | true
			// false  false | false
			// true   false | true
			return $request->getBool( $this->mName ) xor $invert;
		} else {
			return $this->getDefault();
		}
	}
}