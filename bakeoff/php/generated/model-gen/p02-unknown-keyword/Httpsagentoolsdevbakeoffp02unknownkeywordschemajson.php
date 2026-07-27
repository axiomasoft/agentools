<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p02_unknown_keyword;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp02unknownkeywordschemajson
 * @package Bakeoff\p02_unknown_keyword 
 *
 * Схема с вендорным ключом (x-agentools-note) на уровне свойства — генератор обязан игнорировать незнакомый ключ схемы и не терять поле, к которому он привязан.
 *
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp02unknownkeywordschemajson implements JSONModelInterface
{
    

    
        /** @var string Свободный текст заметки. */
        protected $note;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp02unknownkeywordschemajson constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        
            $this->executeBaseValidators($rawModelDataInput);
        

        
            
                $this->processNote($rawModelDataInput);
            
        

        
            if (count($this->_errorRegistry->getErrors())) {
                throw $this->_errorRegistry;
            }
        

        $this->_rawModelDataInput = $rawModelDataInput;

        
    }

    
        protected function executeBaseValidators(array &$modelData): void
        {
            $value = &$modelData;

            
                

if ($additionalProperties =  (static function () use ($modelData): array {
    $additionalProperties = array_diff(array_keys($modelData), array (
   'note',
));

    

    return $additionalProperties;
})()) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\AdditionalPropertiesException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp02unknownkeywordschemajson',
  1 => $additionalProperties,
)));
}

            

            
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
             * Свободный текст заметки.
             *
             * @return string
             */
            public function getNote()
                : string
            {
                

                return $this->note;
            }

            
                /**
                 * Set the value of note.
                 *
                 * @param string $note Свободный текст заметки.
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
                

                $value = array_key_exists('note', $modelData) ? $modelData['note'] : $this->note;

                

                $this->note = $this->validateNote($value, $modelData);
            }

            /**
             * Execute all validators for the property note
             */
            protected function validateNote($value, array $modelData)
            {
                
                    

if (!array_key_exists('note', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'note',
)));
}

                
                    

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
