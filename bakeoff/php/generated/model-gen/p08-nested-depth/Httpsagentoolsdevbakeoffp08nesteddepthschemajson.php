<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p08_nested_depth;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp08nesteddepthschemajson
 * @package Bakeoff\p08_nested_depth 
 *
 * Лимиты SEP-2106 (DoS-пробы, E5): глубокая вложенность объектов (6 уровней) плюс patternProperties на листовом уровне — проверяем, что генератор не падает на глубине и не игнорирует шаблонные ключи.
 *
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp08nesteddepthschemajson implements JSONModelInterface
{
    

    
        /** @var Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03 */
        protected $l1;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp08nesteddepthschemajson constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        
            $this->executeBaseValidators($rawModelDataInput);
        

        
            
                $this->processL1($rawModelDataInput);
            
        

        
            if (count($this->_errorRegistry->getErrors())) {
                throw $this->_errorRegistry;
            }
        

        $this->_rawModelDataInput = $rawModelDataInput;

        
    }

    
        protected function executeBaseValidators(array &$modelData): void
        {
            $value = &$modelData;

            
                

if ($additionalProperties =  (static function () use ($modelData): array {
    $additionalProperties = array_diff(array_keys($modelData), array (
   'l1',
));

    

    return $additionalProperties;
})()) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\AdditionalPropertiesException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson',
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

    
        
            /**
             * Get the value of l1.
             *
             * 
             *
             * @return Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03
             */
            public function getL1()
                : Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03
            {
                

                return $this->l1;
            }

            
                /**
                 * Set the value of l1.
                 *
                 * @param Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03 $l1
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setL1(
                    Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03 $l1
                ): static {
                    if ($this->l1 === $l1) {
                        return $this;
                    }

                    $value = $modelData['l1'] = $l1;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateL1($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->l1 = $value;
                    $this->_rawModelDataInput['l1'] = $l1;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property l1
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processL1(array $modelData): void
            {
                

                $value = array_key_exists('l1', $modelData) ? $modelData['l1'] : $this->l1;

                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03($value) : $value;
    } catch (\Exception $instantiationException) {
        
            $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\NestedObjectException($value ?? null, ...array (
  0 => 'l1',
  1 => $instantiationException,
)));
        

        
            return $instantiationException;
        
    }
})($value)
;

                $this->l1 = $this->validateL1($value, $modelData);
            }

            /**
             * Execute all validators for the property l1
             */
            protected function validateL1($value, array $modelData)
            {
                
                    

if (!array_key_exists('l1', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'l1',
)));
}

                
                    

if (!is_object($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'l1',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'l1',
  1 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
