<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p03_unevaluated;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a36d
 * @package Bakeoff\p03_unevaluated 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a36d implements JSONModelInterface
{
    

    
        /** @var string */
        protected $id;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a36d constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processId($rawModelDataInput);
            
        

        
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
             * Get the value of id.
             *
             * 
             *
             * @return string
             */
            public function getId()
                : string
            {
                

                return $this->id;
            }

            
                /**
                 * Set the value of id.
                 *
                 * @param string $id
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setId(
                    string $id
                ): static {
                    if ($this->id === $id) {
                        return $this;
                    }

                    $value = $modelData['id'] = $id;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateId($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->id = $value;
                    $this->_rawModelDataInput['id'] = $id;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property id
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processId(array $modelData): void
            {
                

                $value = array_key_exists('id', $modelData) ? $modelData['id'] : $this->id;

                

                $this->id = $this->validateId($value, $modelData);
            }

            /**
             * Execute all validators for the property id
             */
            protected function validateId($value, array $modelData)
            {
                
                    

if (!array_key_exists('id', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'id',
)));
}

                
                    

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'id',
  1 => 'string',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
