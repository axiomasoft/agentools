<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p06_if_then_else;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a7d4
 * @package Bakeoff\p06_if_then_else 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a7d4 implements JSONModelInterface
{
    

    
        /** @var mixed */
        protected $promptRef;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a7d4 constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processPromptRef($rawModelDataInput);
            
        

        
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
             * Get the value of prompt_ref.
             *
             * 
             *
             * @return mixed
             */
            public function getPromptRef()
                
            {
                

                return $this->promptRef;
            }

            
                /**
                 * Set the value of prompt_ref.
                 *
                 * @param mixed $promptRef
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setPromptRef(
                     $promptRef
                ): static {
                    if ($this->promptRef === $promptRef) {
                        return $this;
                    }

                    $value = $modelData['prompt_ref'] = $promptRef;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validatePromptRef($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->promptRef = $value;
                    $this->_rawModelDataInput['prompt_ref'] = $promptRef;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property promptRef
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processPromptRef(array $modelData): void
            {
                

                $value = array_key_exists('prompt_ref', $modelData) ? $modelData['prompt_ref'] : $this->promptRef;

                

                $this->promptRef = $this->validatePromptRef($value, $modelData);
            }

            /**
             * Execute all validators for the property promptRef
             */
            protected function validatePromptRef($value, array $modelData)
            {
                
                    

if (!array_key_exists('prompt_ref', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'prompt_ref',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
