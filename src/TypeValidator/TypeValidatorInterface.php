<?php
/**
 * Validator
 *
 * Validator class
 *
 * @package      Mooti
 * @subpackage   Validator
 * @author       Ken Lalobo <ken@mooti.io>
 */

namespace Mooti\Validator\TypeValidator;

interface TypeValidatorInterface
{
    /**
     * Validate some data
     *
     * @param array $constraints The rules
     * @param mixed $data        The data to validate
     * @param mixed $prettyName  Human readable name for the data being validated
     *
     * @return boolean Whether it was valid or not
     */
    public function validate(array $constraints, $data, $prettyName);
}
