<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p08_nested_depth;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87
 * @package Bakeoff\p08_nested_depth 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87 implements JSONModelInterface
{
    

    
        /** @var array Collect all pattern properties provided to the schema grouped by hashed pattern */
        private $_patternProperties = array (
  '26de4bcb99225b7049c750cd3df65882' => 
  array (
  ),
);
    
        /** @var array Maps all pattern properties which are also defined properties of the object to their attribute */
        private $_patternPropertiesMap = array (
);
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87 constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        
            $this->executeBaseValidators($rawModelDataInput);
        

        
            
        
            
        

        
            if (count($this->_errorRegistry->getErrors())) {
                throw $this->_errorRegistry;
            }
        

        $this->_rawModelDataInput = $rawModelDataInput;

        
    }

    
        protected function executeBaseValidators(array &$modelData): void
        {
            $value = &$modelData;

            
                

            $properties = $value;
            $invalidProperties = [];
        
if ((function () use ($properties, &$invalidProperties, $modelData) {
    
        $originalErrorRegistry = $this->_errorRegistry;
    

    $rollbackValues = $this->_patternProperties;

    foreach ($properties as $propertyKey => $value) {
        $propertyKey = (string) $propertyKey;
        try {
            if (!preg_match(base64_decode('L15hdHRyX1thLXowLTldKyQv'), $propertyKey)) {
                continue;
            }

            
                $this->_errorRegistry = new ErrorRegistryException();
            

            

            
                

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'pattern property',
  1 => 'string',
)));
}

            

            
                if ($this->_errorRegistry->getErrors()) {
                    $invalidProperties[$propertyKey] = $this->_errorRegistry->getErrors();
                }
            

            if (!isset($this->_patternPropertiesMap[$propertyKey])) {
                $this->_patternProperties['26de4bcb99225b7049c750cd3df65882'][$propertyKey] = $value;
            }
        } catch (\Exception $e) {
            // collect all errors concerning invalid pattern properties
            isset($invalidProperties[$propertyKey])
                ? $invalidProperties[$propertyKey][] = $e
                : $invalidProperties[$propertyKey] = [$e];
        }
    }

    
        $this->_errorRegistry = $originalErrorRegistry;
    

    if (!empty($invalidProperties)) {
        $this->_patternProperties = $rollbackValues;
    }

    return !empty($invalidProperties);
})()
) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidPatternPropertiesException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87',
  1 => '^attr_[a-z0-9]+$',
  2 => $invalidProperties,
)));
}

            
                

if ($additionalProperties =  (static function () use ($modelData): array {
    $additionalProperties = array_diff(array_keys($modelData), array (
));

    
        // filter out all pattern properties
        $additionalProperties = array_filter(
            $additionalProperties,
            static function (string $property): bool {
                return preg_match('/^attr_[a-z0-9]+$/', $property) !== 1;
            }
        );
    

    return $additionalProperties;
})()) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\AdditionalPropertiesException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87',
  1 => $additionalProperties,
)));
}

            

            
        }
    

    /**
     * Get the raw input used to set up the model
     *
     * @return array
     */
    public function getRawModelDataInput(): array
    {
        return $this->_rawModelDataInput;
    }

    
        
    
        
    

    
}

// @codeCoverageIgnoreEnd
