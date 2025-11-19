<?php

namespace FluentCart\App\Services\Localization;

class PostcodeVerification
{
	public function formatPostcode($postcode, $country)
	{
		$postcode = $this->normalizePostcode($postcode ?? '');

		switch ($country) {
			case 'SE':
				$postcode = substr_replace($postcode, ' ', -2, 0);
				break;
			case 'CA':
			case 'GB':
				$postcode = substr_replace($postcode, ' ', -3, 0);
				break;
			case 'IE':
				$postcode = substr_replace($postcode, ' ', 3, 0);
				break;
			case 'BR':
			case 'PL':
				$postcode = substr_replace($postcode, '-', -3, 0);
				break;
			case 'JP':
				$postcode = substr_replace($postcode, '-', 3, 0);
				break;
			case 'PT':
				$postcode = substr_replace($postcode, '-', 4, 0);
				break;
			case 'PR':
			case 'US':
			case 'MN':
				$postcode = rtrim(substr_replace($postcode, '-', 5, 0), '-');
				break;
			case 'NL':
				$postcode = substr_replace($postcode, ' ', 4, 0);
				break;
			case 'LV':
				$postcode = preg_replace('/^(LV)?-?(\d+)$/', 'LV-${2}', $postcode);
				break;
			case 'CZ':
			case 'SK':
				$postcode = preg_replace("/^({$country})-?(\d+)$/", '${1}-${2}', $postcode);
				$postcode = substr_replace($postcode, ' ', -2, 0);
				break;
			case 'DK':
				$postcode = preg_replace('/^(DK)(.+)$/', '${1}-${2}', $postcode);
				break;
		}

		return apply_filters('fluent_cart/address/postcode/format', trim($postcode), $country);
	}

	/**
	 * Normalize postcodes.
	 *
	 * Remove spaces and convert characters to uppercase.
	 *
	 * @param string $postcode Postcode.
	 * @return string
	 */
	public function normalizePostcode($postcode)
	{
		return preg_replace('/[\s\-]/', '', trim(self::fctStrtoupper($postcode ?? '')));
	}

    public static function fctStrtoupper( $string )
    {
        $string = $string ?? '';
        return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $string ) : strtoupper( $string );
    }


    public function isValid($postcode, $country)
	{
		if (strlen(trim(preg_replace('/[\s\-A-Za-z0-9]/', '', $postcode))) > 0) {
			return false;
		}

		switch ($country) {
			case 'AT':
			case 'BE':
			case 'CH':
			case 'HU':
			case 'NO':
				$valid = (bool) preg_match('/^([0-9]{4})$/', $postcode);
				break;
			case 'BA':
				$valid = (bool) preg_match('/^([7-8]{1})([0-9]{4})$/', $postcode);
				break;
			case 'BR':
				$valid = (bool) preg_match('/^([0-9]{5})([-])?([0-9]{3})$/', $postcode);
				break;
			case 'DE':
				$valid = (bool) preg_match('/^([0]{1}[1-9]{1}|[1-9]{1}[0-9]{1})[0-9]{3}$/', $postcode);
				break;
			case 'DK':
				$valid = (bool) preg_match('/^(DK-)?([1-24-9]\d{3}|3[0-8]\d{2})$/', $postcode);
				break;
			case 'ES':
			case 'FI':
			case 'EE':
			case 'FR':
			case 'IT':
				$valid = (bool) preg_match('/^([0-9]{5})$/i', $postcode);
				break;
			case 'GB':
				$valid = self::isGBPostcode($postcode);
				break;
			case 'IE':
				$valid = (bool) preg_match('/([AC-FHKNPRTV-Y]\d{2}|D6W)[0-9AC-FHKNPRTV-Y]{4}/', $this->normalizePostcode($postcode));
				break;
			case 'IN':
				$valid = (bool) preg_match('/^[1-9]{1}[0-9]{2}\s{0,1}[0-9]{3}$/', $postcode);
				break;
			case 'JP':
				$valid = (bool) preg_match('/^([0-9]{3})([-]?)([0-9]{4})$/', $postcode);
				break;
			case 'PT':
				$valid = (bool) preg_match('/^([0-9]{4})([-])([0-9]{3})$/', $postcode);
				break;
			case 'PR':
			case 'US':
				$valid = (bool) preg_match('/^([0-9]{5})(-[0-9]{4})?$/i', $postcode);
				break;
			case 'CA':
				// CA Postal codes cannot contain D,F,I,O,Q,U and cannot start with W or Z. https://en.wikipedia.org/wiki/Postal_codes_in_Canada#Number_of_possible_postal_codes.
				$valid = (bool) preg_match('/^([ABCEGHJKLMNPRSTVXY]\d[ABCEGHJKLMNPRSTVWXYZ])([\ ])?(\d[ABCEGHJKLMNPRSTVWXYZ]\d)$/i', $postcode);
				break;
			case 'PL':
				$valid = (bool) preg_match('/^([0-9]{2})([-])([0-9]{3})$/', $postcode);
				break;
			case 'CZ':
			case 'SE':
			case 'SK':
				$valid = (bool) preg_match("/^($country-)?([0-9]{3})(\s?)([0-9]{2})$/", $postcode);
				break;
			case 'NL':
				$valid = (bool) preg_match('/^([1-9][0-9]{3})(\s?)(?!SA|SD|SS)[A-Z]{2}$/i', $postcode);
				break;
			case 'SI':
				$valid = (bool) preg_match('/^([1-9][0-9]{3})$/', $postcode);
				break;
			case 'LI':
				$valid = (bool) preg_match('/^(94[8-9][0-9])$/', $postcode);
				break;
			default:
				$valid = true;
				break;
		}

		return apply_filters('fluent_cart/address/postcode/is_valid', $valid, $postcode, $country);
	}

    public static function isGBPostcode( $to_check ) {

        // Permitted letters depend upon their position in the postcode.
        // https://en.wikipedia.org/wiki/Postcodes_in_the_United_Kingdom#Validation.
        $alpha1 = '[abcdefghijklmnoprstuwyz]'; // Character 1.
        $alpha2 = '[abcdefghklmnopqrstuvwxy]'; // Character 2.
        $alpha3 = '[abcdefghjkpstuw]';         // Character 3 == ABCDEFGHJKPSTUW.
        $alpha4 = '[abehmnprvwxy]';            // Character 4 == ABEHMNPRVWXY.
        $alpha5 = '[abdefghjlnpqrstuwxyz]';    // Character 5 != CIKMOV.

        $pcexp = array();

        // Expression for postcodes: AN NAA, ANN NAA, AAN NAA, and AANN NAA.
        $pcexp[0] = '/^(' . $alpha1 . '{1}' . $alpha2 . '{0,1}[0-9]{1,2})([0-9]{1}' . $alpha5 . '{2})$/';

        // Expression for postcodes: ANA NAA.
        $pcexp[1] = '/^(' . $alpha1 . '{1}[0-9]{1}' . $alpha3 . '{1})([0-9]{1}' . $alpha5 . '{2})$/';

        // Expression for postcodes: AANA NAA.
        $pcexp[2] = '/^(' . $alpha1 . '{1}' . $alpha2 . '[0-9]{1}' . $alpha4 . ')([0-9]{1}' . $alpha5 . '{2})$/';

        // Exception for the special postcode GIR 0AA.
        $pcexp[3] = '/^(gir)(0aa)$/';

        // Standard BFPO numbers.
        $pcexp[4] = '/^(bfpo)([0-9]{1,4})$/';

        // c/o BFPO numbers.
        $pcexp[5] = '/^(bfpo)(c\/o[0-9]{1,3})$/';

        // Load up the string to check, converting into lowercase and removing spaces.
        $postcode = strtolower( $to_check );
        $postcode = str_replace( ' ', '', $postcode );

        // Assume we are not going to find a valid postcode.
        $valid = false;

        // Check the string against the six types of postcodes.
        foreach ( $pcexp as $regexp ) {
            if ( preg_match( $regexp, $postcode, $matches ) ) {
                // Remember that we have found that the code is valid and break from loop.
                $valid = true;
                break;
            }
        }

        return $valid;
    }
}
