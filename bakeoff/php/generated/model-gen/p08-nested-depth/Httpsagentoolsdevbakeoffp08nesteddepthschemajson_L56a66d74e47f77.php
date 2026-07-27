<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p08_nested_depth;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77
 * @package Bakeoff\p08_nested_depth 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77 implements JSONModelInterface
{
    

    
        /** @var Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87 */
        protected $l6;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L56a66d74e47f77 constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processL6($rawModelDataInput);
            
        

        
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
             * Get the value of l6.
             *
             * 
             *
             * @return Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87
             */
            public function getL6()
                : Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87
            {
                

                return $this->l6;
            }

            
                /**
                 * Set the value of l6.
                 *
                 * @param Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87 $l6
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setL6(
                    Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87 $l6
                ): static {
                    if ($this->l6 === $l6) {
                        return $this;
                    }

                    $value = $modelData['l6'] = $l6;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateL6($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->l6 = $value;
                    $this->_rawModelDataInput['l6'] = $l6;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property l6
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processL6(array $modelData): void
            {
                

                $value = array_key_exists('l6', $modelData) ? $modelData['l6'] : $this->l6;

                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87($value) : $value;
    } catch (\Exception $instantiationException) {
        
            $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\NestedObjectException($value ?? null, ...array (
  0 => 'l6',
  1 => $instantiationException,
)));
        

        
            return $instantiationException;
        
    }
})($value)
;

                $this->l6 = $this->validateL6($value, $modelData);
            }

            /**
             * Execute all validators for the property l6
             */
            protected function validateL6($value, array $modelData)
            {
                
                    

if (!array_key_exists('l6', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'l6',
)));
}

                
                    

if (!is_object($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'l6',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'l6',
  1 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L66a66d74e47f87',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
