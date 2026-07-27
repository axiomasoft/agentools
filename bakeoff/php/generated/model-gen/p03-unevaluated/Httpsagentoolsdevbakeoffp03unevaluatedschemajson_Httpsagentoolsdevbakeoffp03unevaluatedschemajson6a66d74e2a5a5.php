<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p03_unevaluated;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a5a5
 * @package Bakeoff\p03_unevaluated 
 *

 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a5a5 implements JSONModelInterface
{
    

    
        /** @var string|null */
        protected $note;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a5a5 constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        

        
            
                $this->processNote($rawModelDataInput);
            
        

        
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
             * Get the value of note.
             *
             * 
             *
             * @return string|null
             */
            public function getNote()
                : ?string
            {
                

                return $this->note;
            }

            
                /**
                 * Set the value of note.
                 *
                 * @param string $note
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setNote(
                    string $note
                ): static {
                    if ($this->note === $note) {
                        return $this;
                    }

                    $value = $modelData['note'] = $note;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateNote($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->note = $value;
                    $this->_rawModelDataInput['note'] = $note;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property note
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processNote(array $modelData): void
            {
                
                    if (!array_key_exists('note', $modelData) && $this->note === null) {
                        return;
                    }
                

                $value = array_key_exists('note', $modelData) ? $modelData['note'] : $this->note;

                

                $this->note = $this->validateNote($value, $modelData);
            }

            /**
             * Execute all validators for the property note
             */
            protected function validateNote($value, array $modelData)
            {
                
                    

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'note',
  1 => 'string',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
