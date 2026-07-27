<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p06_if_then_else;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a5c3
 * @package Bakeoff\p06_if_then_else 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a5c3 implements JSONModelInterface
{
    

    
        /** @var string|null */
        protected $kind;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a5c3 constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processKind($rawModelDataInput);
            
        

        
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
             * Get the value of kind.
             *
             * 
             *
             * @return string|null
             */
            public function getKind()
                : ?string
            {
                

                return $this->kind;
            }

            
                /**
                 * Set the value of kind.
                 *
                 * @param string $kind
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setKind(
                    string $kind
                ): static {
                    if ($this->kind === $kind) {
                        return $this;
                    }

                    $value = $modelData['kind'] = $kind;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateKind($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->kind = $value;
                    $this->_rawModelDataInput['kind'] = $kind;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property kind
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processKind(array $modelData): void
            {
                
                    if (!array_key_exists('kind', $modelData) && $this->kind === null) {
                        return;
                    }
                

                $value = array_key_exists('kind', $modelData) ? $modelData['kind'] : $this->kind;

                

                $this->kind = $this->validateKind($value, $modelData);
            }

            /**
             * Execute all validators for the property kind
             */
            protected function validateKind($value, array $modelData)
            {
                
                    

if (array_key_exists('kind', $modelData) && $value !== 'llm') {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidConstException($value ?? null, ...array (
  0 => 'kind',
  1 => 'llm',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
