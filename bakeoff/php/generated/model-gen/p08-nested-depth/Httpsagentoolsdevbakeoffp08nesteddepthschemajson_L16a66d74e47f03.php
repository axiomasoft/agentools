<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p08_nested_depth;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03
 * @package Bakeoff\p08_nested_depth 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03 implements JSONModelInterface
{
    

    
        /** @var Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c */
        protected $l2;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L16a66d74e47f03 constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processL2($rawModelDataInput);
            
        

        
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
             * Get the value of l2.
             *
             * 
             *
             * @return Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c
             */
            public function getL2()
                : Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c
            {
                

                return $this->l2;
            }

            
                /**
                 * Set the value of l2.
                 *
                 * @param Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c $l2
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setL2(
                    Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c $l2
                ): static {
                    if ($this->l2 === $l2) {
                        return $this;
                    }

                    $value = $modelData['l2'] = $l2;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateL2($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->l2 = $value;
                    $this->_rawModelDataInput['l2'] = $l2;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property l2
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processL2(array $modelData): void
            {
                

                $value = array_key_exists('l2', $modelData) ? $modelData['l2'] : $this->l2;

                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c($value) : $value;
    } catch (\Exception $instantiationException) {
        
            $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\NestedObjectException($value ?? null, ...array (
  0 => 'l2',
  1 => $instantiationException,
)));
        

        
            return $instantiationException;
        
    }
})($value)
;

                $this->l2 = $this->validateL2($value, $modelData);
            }

            /**
             * Execute all validators for the property l2
             */
            protected function validateL2($value, array $modelData)
            {
                
                    

if (!array_key_exists('l2', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'l2',
)));
}

                
                    

if (!is_object($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'l2',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'l2',
  1 => 'Httpsagentoolsdevbakeoffp08nesteddepthschemajson_L26a66d74e47f2c',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
