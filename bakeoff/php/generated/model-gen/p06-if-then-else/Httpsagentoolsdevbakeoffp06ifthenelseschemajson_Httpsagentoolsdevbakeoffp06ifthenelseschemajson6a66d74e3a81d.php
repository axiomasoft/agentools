<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p06_if_then_else;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a81d
 * @package Bakeoff\p06_if_then_else 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a81d implements JSONModelInterface
{
    

    
        /** @var mixed */
        protected $tool;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a81d constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processTool($rawModelDataInput);
            
        

        
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
             * Get the value of tool.
             *
             * 
             *
             * @return mixed
             */
            public function getTool()
                
            {
                

                return $this->tool;
            }

            
                /**
                 * Set the value of tool.
                 *
                 * @param mixed $tool
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setTool(
                     $tool
                ): static {
                    if ($this->tool === $tool) {
                        return $this;
                    }

                    $value = $modelData['tool'] = $tool;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateTool($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->tool = $value;
                    $this->_rawModelDataInput['tool'] = $tool;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property tool
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processTool(array $modelData): void
            {
                

                $value = array_key_exists('tool', $modelData) ? $modelData['tool'] : $this->tool;

                

                $this->tool = $this->validateTool($value, $modelData);
            }

            /**
             * Execute all validators for the property tool
             */
            protected function validateTool($value, array $modelData)
            {
                
                    

if (!array_key_exists('tool', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'tool',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
