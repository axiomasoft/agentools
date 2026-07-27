<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p08_nested_depth;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46
 * @package Bakeoff\p08_nested_depth 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46 implements JSONModelInterface
{
    

    
        /** @var Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f */
        protected $l4;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L36a66d74e47f46 constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processL4($rawModelDataInput);
            
        

        
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
             * Get the value of l4.
             *
             * 
             *
             * @return Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f
             */
            public function getL4()
                : Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f
            {
                

                return $this->l4;
            }

            
                /**
                 * Set the value of l4.
                 *
                 * @param Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f $l4
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setL4(
                    Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f $l4
                ): static {
                    if ($this->l4 === $l4) {
                        return $this;
                    }

                    $value = $modelData['l4'] = $l4;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateL4($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->l4 = $value;
                    $this->_rawModelDataInput['l4'] = $l4;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property l4
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processL4(array $modelData): void
            {
                

                $value = array_key_exists('l4', $modelData) ? $modelData['l4'] : $this->l4;

                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f($value) : $value;
    } catch (\Exception $instantiationException) {
        
            $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\NestedObjectException($value ?? null, ...array (
  0 => 'l4',
  1 => $instantiationException,
)));
        

        
            return $instantiationException;
        
    }
})($value)
;

                $this->l4 = $this->validateL4($value, $modelData);
            }

            /**
             * Execute all validators for the property l4
             */
            protected function validateL4($value, array $modelData)
            {
                
                    

if (!array_key_exists('l4', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'l4',
)));
}

                
                    

if (!is_object($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'l4',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'l4',
  1 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
