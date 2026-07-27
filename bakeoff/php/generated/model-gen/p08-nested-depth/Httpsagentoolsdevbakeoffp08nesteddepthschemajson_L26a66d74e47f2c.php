<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p08_nested_depth;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c
 * @package Bakeoff\p08_nested_depth 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c implements JSONModelInterface
{
    

    
        /** @var Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46 */
        protected $l3;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processL3($rawModelDataInput);
            
        

        
            if (count($this->_errorRegistry->getErrors())) {
                throw $this->_errorRegistry;
            }
        

        $this->_rawModelDataInput = $rawModelDataInput;

        
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
             * Get the value of l3.
             *
             * 
             *
             * @return Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46
             */
            public function getL3()
                : Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46
            {
                

                return $this->l3;
            }

            
                /**
                 * Set the value of l3.
                 *
                 * @param Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46 $l3
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setL3(
                    Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46 $l3
                ): static {
                    if ($this->l3 === $l3) {
                        return $this;
                    }

                    $value = $modelData['l3'] = $l3;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateL3($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->l3 = $value;
                    $this->_rawModelDataInput['l3'] = $l3;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property l3
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processL3(array $modelData): void
            {
                

                $value = array_key_exists('l3', $modelData) ? $modelData['l3'] : $this->l3;

                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46($value) : $value;
    } catch (\Exception $instantiationException) {
        
            $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\NestedObjectException($value ?? null, ...array (
  0 => 'l3',
  1 => $instantiationException,
)));
        

        
            return $instantiationException;
        
    }
})($value)
;

                $this->l3 = $this->validateL3($value, $modelData);
            }

            /**
             * Execute all validators for the property l3
             */
            protected function validateL3($value, array $modelData)
            {
                
                    

if (!array_key_exists('l3', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'l3',
)));
}

                
                    

if (!is_object($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'l3',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'l3',
  1 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
