<?php
/**
 * Validator
 *
 * Main validator class
 *
 * This is the main class for validation. It will validate a data structure
 * based on the validation rules you give it.
 *
 * @package      Mooti
 * @subpackage   Validator
 * @author       Ken Lalobo <ken@mooti.io>
 */

namespace Mooti\Validator;

use Mooti\Factory\Factory;
use Mooti\Validator\Exception\InvalidRuleException;
use Mooti\Validator\Exception\DataValidationException;
use Mooti\Validator\Exception\InvalidTypeValidatorException;
use Mooti\Validator\TypeValidator\TypeValidatorInterface;
use Psr\Log\InvalidArgumentException;

class Validator
{
    use Factory;

    const TYPE_STRING  = 'string';
    const TYPE_NUMBER  = 'number';
    const TYPE_OBJECT  = 'object';
    const TYPE_ARRAY   = 'array';
    const TYPE_BOOLEAN = 'boolean';

    protected $allowedTypeValidators = [
        self::TYPE_STRING,
        self::TYPE_NUMBER,
        self::TYPE_OBJECT,
        self::TYPE_ARRAY,
        self::TYPE_BOOLEAN
    ];

    protected $errors = [];
    protected $typeValidators = [];

    /**
     * Add an error to the internal error array
     *
     * @param string $errorKey   The key to use for the error
     * @param string $errorValue The description of the error
     *
     */
    public function addError($errorKey, $errorValue)
    {
        if (!isset($this->errors[$errorKey])) {
            $this->errors[$errorKey] = [];
        }
        $this->errors[$errorKey][] = $errorValue;
    }

    /**
     * Indicates wether we have errors or not     
     *
     * @return boolean Wether there are any erros
     */
    public function hasErrors()
    {
        return (sizeof($this->errors) > 0);
    }

    /**
     * Get all errors
     *
     * @return array An array of errors
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Validate data against a set of validation rules.
     * This will attempt to validate the members of the given array/object 
     *
     * @param array $validationRules The validaton rules
     * @param mixed $data            The data to validate
     *
     * @throws InvalidRuleException
     * @return boolean Whether it was valid or not
     */
    public function isValid(array $validationRules, $data, $nameSpace = '')
    {
        foreach ($validationRules as $itemKey => $validationRule) {

            // All rules need to have a type
            if (isset($validationRule['type']) == false) {
                throw new InvalidRuleException(
                   sprintf('%s does not have a "type" property. All rules must have a "type" property', $itemKey)
                );
            }

            // There can only be one rule if using wildcards
            if ($itemKey == '*' && count($validationRules) > 1) {
                throw new InvalidRuleException(
                    sprintf('%s has more than one rule. You cannot have more than one rule if using a wildcard', $itemKey)
                );
            }

            try {
                $fullyQualifiedName = $nameSpace . (empty($nameSpace) == false ?'.':'') .$itemKey;
                $this->validateData($validationRule, $itemKey, $data, $fullyQualifiedName);
            } catch (DataValidationException $e) {
                $this->addError($fullyQualifiedName, $e->getMessage());
            }
        };

        if ($this->hasErrors()) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Validate some data when given a validation rule. The key corresponds to an item in the data array/object.
     * If a wildcard is used (*) then it corresponds to all items but data must be then be an array
     *
     * @param array  $validationRule     The rule to validate against
     * @param string $itemKey            The key for the item in data to validate. Use * for all items
     * @param mixed  $data               The data to validate against. should be an array or an object
     * @param string $fullyQualifiedName The fully qualified name of the item being validated     
     *
     * @throws DataValidationException
     * @throws InvalidRuleException
     */
    public function validateData(array $validationRule, $itemKey, $data, $fullyQualifiedName)
    {
        if ($itemKey == '*') {
            $this->validateMultipleItems($validationRule, $data, $fullyQualifiedName);
        } else {
            // All named rules need to let us know if they are required or not
            if (isset($validationRule['required']) == false) {
                throw new InvalidRuleException(
                    sprintf('%s does not have a "required". All named rules must have a "required" property', $itemKey)
                );
            }

            if ($validationRule['required'] == true && $this->propertyExists($itemKey, $data) == false) {
                $prettyName = isset($validationRule['name'])?$validationRule['name']:'This value';
                throw new DataValidationException(sprintf('%s is required', $prettyName));
            } elseif ($validationRule['required'] == false && $this->propertyExists($itemKey, $data) == false) {
                //The item does not exist and is not required so no need to validate
                return;
            }

            $item = $this->getProperty($itemKey, $data);

            $nullable = isset($validationRule['nullable']) ? (bool) $validationRule['nullable'] : false;
            if ($nullable == true && is_null($item)) {
                return;
            }

            $this->validateItem($validationRule, $item, $fullyQualifiedName);
        }
    }

    /**
     * Validate a single item of data and any properties/items it has (if it is an object/array) using the given validation rule.
     *
     * @param array $validationRule The rule to validate against
     * @param string $item The item to validate
     * @param string $fullyQualifiedName The fully qualified name of the item being validated
     * @throws InvalidRuleException
     */
    public function validateItem(array $validationRule, $item, $fullyQualifiedName)
    {
        $typeValidator = $this->getTypeValidator($validationRule['type']);

        $constraints = isset($validationRule['constraints']) ? $validationRule['constraints'] : [];
        $prettyName = isset($validationRule['name'])?$validationRule['name']:'This value';
        $typeValidator->validate($constraints, $item, $prettyName);

        $validationType = $validationRule['type'];

        if ($validationType == 'object' && isset($validationRule['inheritance']) && is_array($validationRule['inheritance'])) {
            if (empty($validationRule['inheritance']['discriminator'])) {
                throw new InvalidRuleException(sprintf('inheritance needs a discriminator in %s', $fullyQualifiedName));
            }
            $discriminator = $validationRule['inheritance']['discriminator'];
            $discriminatorValue = $item->$discriminator ?? $item[$discriminator] ?? null;

            $discriminatorRequired = $validationRule['properties'][$discriminator]['required'] ?? false;

            if ($discriminatorRequired == false) {
                throw new InvalidRuleException(sprintf(
                    'discriminator %s in %s is has to be a required value',
                    $discriminator,
                    $fullyQualifiedName
                ));
            }
            $extraProperties = $validationRule['inheritance']['properties'][strtolower($discriminatorValue)] ?? null;
        } else {
            $extraProperties = null;
        }

        if ($validationType == 'object' && isset($validationRule['properties']) && is_array($validationRule['properties'])) {

            if (!empty($extraProperties)) {
                $properties = array_merge($validationRule['properties'], $extraProperties);
            } else {
                $properties = $validationRule['properties'];
            }

            $this->isValid($properties, $item, $fullyQualifiedName);

        } elseif ($validationType == 'array' && isset($validationRule['items']) && is_array($validationRule['items'])) {
            $this->isValid($validationRule['items'], $item, $fullyQualifiedName);
        }
    }

    /**
     * Validate a multile items using the given validation rule.
     *
     * @param array  $validationRule     The rule to validate against     
     * @param array  $items              The and array of items
     * @param string $fullyQualifiedName The fully qualified name of the item being validated
     *
     */
    public function validateMultipleItems(array $validationRule, array $items, $fullyQualifiedName)
    {
        $itemNumber = 1;
        foreach ($items as $item) {
            $tempRule = $validationRule;
            $tempRule['name'] = empty($validationRule['name'])?'Value number '.$itemNumber:$validationRule['name'].' number '.$itemNumber;
            $this->validateItem($tempRule, $item, $fullyQualifiedName);
            $itemNumber++;
        }
    }

    /**
     * Determines wether an object or array has a given property/key.
     *
     * @param string  $property The name of the property/key
     * @param array  $item     The item to check against
     *
     * @return boolean. Whether it exists or not
     */
    public function propertyExists($property, $item)
    {
        if (gettype($item) == 'array') {
            return array_key_exists($property, $item);
        } elseif (gettype($item) == 'object') {
            return property_exists($item , $property);
        }
        return false;
    }

    /**
     * Get a property/key from an object/arrray.
     *
     * @param string  $property The name of the property/key
     * @param array  $item     The item to retrieve from
     *
     * @return mixed The property/key value
     */
    public function getProperty($property, $item)
    {
        if (gettype($item) == 'array') {
            return $item[$property];
        } elseif (gettype($item) == 'object') {
            return $item->$property;
        }
        return null;
    }

    /**
     * Get a type validator
     *
     * @param string $type. The data type to get the validator for
     *
     * @throws InvalidTypeValidatorException
     * @return TypeValidatorInterface The type validator
     */
    public function getTypeValidator($type)
    {
        if (in_array($type, $this->allowedTypeValidators, true) == false) {
            throw new InvalidTypeValidatorException('The type "'.$type.'" is invalid');
        }

        if (isset($this->typeValidators[$type]) == false) {
            $className = 'Mooti\\Validator\\TypeValidator\\'.ucfirst($type).'Validator';
            $this->typeValidators[$type] = $this->createNew($className);
        }

        return $this->typeValidators[$type];
    }
}
