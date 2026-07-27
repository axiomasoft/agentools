<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p08_nested_depth;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f
 * @package Bakeoff\p08_nested_depth 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f implements JSONModelInterface
{
    

    
        /** @var Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77 */
        protected $l5;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L46a66d74e47f5f constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processL5($rawModelDataInput);
            
        

        
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
             * Get the value of l5.
             *
             * 
             *
             * @return Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77
             */
            public function getL5()
                : Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77
            {
                

                return $this->l5;
            }

            
                /**
                 * Set the value of l5.
                 *
                 * @param Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77 $l5
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setL5(
                    Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77 $l5
                ): static {
                    if ($this->l5 === $l5) {
                        return $this;
                    }

                    $value = $modelData['l5'] = $l5;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateL5($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->l5 = $value;
                    $this->_rawModelDataInput['l5'] = $l5;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property l5
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processL5(array $modelData): void
            {
                

                $value = array_key_exists('l5', $modelData) ? $modelData['l5'] : $this->l5;

                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77($value) : $value;
    } catch (\Exception $instantiationException) {
        
            $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\NestedObjectException($value ?? null, ...array (
  0 => 'l5',
  1 => $instantiationException,
)));
        

        
            return $instantiationException;
        
    }
})($value)
;

                $this->l5 = $this->validateL5($value, $modelData);
            }

            /**
             * Execute all validators for the property l5
             */
            protected function validateL5($value, array $modelData)
            {
                
                    

if (!array_key_exists('l5', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'l5',
)));
}

                
                    

if (!is_object($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'l5',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'l5',
  1 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
