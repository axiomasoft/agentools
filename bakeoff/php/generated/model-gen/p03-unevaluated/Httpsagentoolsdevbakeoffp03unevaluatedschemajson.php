<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p03_unevaluated;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp03unevaluatedschemajson
 * @package Bakeoff\p03_unevaluated 
 *
 * Закрытая схема авторинга (J9): unevaluatedProperties:false на внешнем уровне allOf-композиции. Опубликованные схемы этот ключ не несут (J9); проба проверяет, переживает ли генератор его в схеме авторинга.
 *
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp03unevaluatedschemajson implements JSONModelInterface
{
    

    
        /** @var string */
        protected $id;
    
        /** @var string|null */
        protected $note;
    
        /** @var array Track the internal validation state of composed validations */
        private $_propertyValidationState = array (
  0 => 
  array (
  ),
);
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp03unevaluatedschemajson constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        
            $this->executeBaseValidators($rawModelDataInput);
        

        
            
                $this->processId($rawModelDataInput);
            
        
            
                $this->processNote($rawModelDataInput);
            
        
            
        

        
            if (count($this->_errorRegistry->getErrors())) {
                throw $this->_errorRegistry;
            }
        

        $this->_rawModelDataInput = $rawModelDataInput;

        
    }

    
        protected function executeBaseValidators(array &$modelData): void
        {
            $value = &$modelData;

            

            
                $this->validateComposition_0($modelData);
            
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
                 * 
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
                    

                    $this->validateComposition_0($modelData);



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
             * 
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
                

                return $value;
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
                 * 
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
                    

                    $this->validateComposition_0($modelData);



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
             * 
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
                

                return $value;
            }
        
    
        
    

    /**
 * Validate updated properties which are part of a composition validation
 *
 * @param array $modifiedModelData An array containing all updated data as key-value pairs
 *
 * 
 */
private function validateComposition_0(array &$modifiedModelData): void
{
    $validatorIndex = 0;
    $value = $modelData = array_merge($this->_rawModelDataInput, $modifiedModelData);

    
            $succeededCompositionElements = 0;
            $compositionErrorCollection = [];
        
    if (
(function (&$value) use (
    &$modelData,
    &$modifiedModelData,
    &$compositionErrorCollection,
    &$succeededCompositionElements,
    &$validatorIndex
) {
    $succeededCompositionElements = 2;
    $validatorComponentIndex = 0;
    $originalModelData = $value;
    $proposedValue = null;
    $modifiedValues = [];

    
        $originalPropertyValidationState = $this->_propertyValidationState ?? [];
    

    
        $originalErrorRegistry = $this->_errorRegistry;
    

    
        try {
            
                // check if the state of the validator is already known.
                // If none of the properties affected by the validator are changed the validator must not be re-evaluated
                if (isset($validatorIndex) &&
                    isset($this->_propertyValidationState[$validatorIndex][$validatorComponentIndex]) &&
                    !array_intersect(
                        array_keys($modifiedModelData),
                        [
                            
                                'id',
                            
                        ]
                    )
                ) {
                    
                        $compositionErrorCollection[] = $this->_propertyValidationState[$validatorIndex][$validatorComponentIndex];
                    

                    if (
                            $this->_propertyValidationState[$validatorIndex][$validatorComponentIndex]->getErrors()
                        
                    ) {
                        throw new \Exception();
                    }
                } else {
            

                
                    // collect errors for each composition element
                    $this->_errorRegistry = new ErrorRegistryException();
                

                

                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a36d($value) : $value;
    } catch (\Exception $instantiationException) {
        
            
                foreach($instantiationException->getErrors() as $nestedValidationError) {
                    $this->_errorRegistry->addError($nestedValidationError);
                }
            
        

        
            return $instantiationException;
        
    }
})($value)
;

                
                    

if (!is_object($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp03unevaluatedschemajson',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a36d)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp03unevaluatedschemajson',
  1 => 'Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a36d',
)));
}

                

                
                    $compositionErrorCollection[] = $this->_errorRegistry;

                    
                        if (isset($validatorIndex)) {
                            $this->_propertyValidationState[$validatorIndex][$validatorComponentIndex] = $this->_errorRegistry;
                        }
                    

                    // an error inside the composed validation occurred. Throw an exception to count the validity of the
                    // composition item
                    if ($this->_errorRegistry->getErrors()) {
                        throw new \Exception();
                    }
                

                
                    $proposedValue = $proposedValue ?? $value;
                

                if (is_object($value)) {
                    $modifiedValues = array_merge($modifiedValues, $this->_getModifiedValues_45662($originalModelData, $value));
                }
            
                
            }
            
        } catch (\Exception $e) {
            

            
                $modelData['id'] = null;
            

            $succeededCompositionElements--;
        }

        $value = $originalModelData;
        $validatorComponentIndex++;
    
        try {
            
                // check if the state of the validator is already known.
                // If none of the properties affected by the validator are changed the validator must not be re-evaluated
                if (isset($validatorIndex) &&
                    isset($this->_propertyValidationState[$validatorIndex][$validatorComponentIndex]) &&
                    !array_intersect(
                        array_keys($modifiedModelData),
                        [
                            
                                'note',
                            
                        ]
                    )
                ) {
                    
                        $compositionErrorCollection[] = $this->_propertyValidationState[$validatorIndex][$validatorComponentIndex];
                    

                    if (
                            $this->_propertyValidationState[$validatorIndex][$validatorComponentIndex]->getErrors()
                        
                    ) {
                        throw new \Exception();
                    }
                } else {
            

                
                    // collect errors for each composition element
                    $this->_errorRegistry = new ErrorRegistryException();
                

                

                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a5a5($value) : $value;
    } catch (\Exception $instantiationException) {
        
            
                foreach($instantiationException->getErrors() as $nestedValidationError) {
                    $this->_errorRegistry->addError($nestedValidationError);
                }
            
        

        
            return $instantiationException;
        
    }
})($value)
;

                
                    

if (!is_object($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp03unevaluatedschemajson',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a5a5)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp03unevaluatedschemajson',
  1 => 'Httpsagentoolsdevbakeoffp03unevaluatedschemajson_Httpsagentoolsdevbakeoffp03unevaluatedschemajson6a66d74e2a5a5',
)));
}

                

                
                    $compositionErrorCollection[] = $this->_errorRegistry;

                    
                        if (isset($validatorIndex)) {
                            $this->_propertyValidationState[$validatorIndex][$validatorComponentIndex] = $this->_errorRegistry;
                        }
                    

                    // an error inside the composed validation occurred. Throw an exception to count the validity of the
                    // composition item
                    if ($this->_errorRegistry->getErrors()) {
                        throw new \Exception();
                    }
                

                
                    $proposedValue = $proposedValue ?? $value;
                

                if (is_object($value)) {
                    $modifiedValues = array_merge($modifiedValues, $this->_getModifiedValues_45662($originalModelData, $value));
                }
            
                
            }
            
        } catch (\Exception $e) {
            

            
                $modelData['note'] = null;
            

            $succeededCompositionElements--;
        }

        $value = $originalModelData;
        $validatorComponentIndex++;
    

    
        $value = $proposedValue;
    

    
        $this->_errorRegistry = $originalErrorRegistry;
    

    $result = !($succeededCompositionElements === 2);

    
        if ($result) {
            $this->_propertyValidationState = $originalPropertyValidationState;
        }
    

    return $result;
})($value)
) {
        $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\ComposedValue\AllOfException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp03unevaluatedschemajson',
  1 => $succeededCompositionElements,
  2 => $compositionErrorCollection,
)));
    }

    foreach (array_keys($modifiedModelData) as $property) {
        $modifiedModelData[$property] = $modelData[$property];
    }
}



                        private function _getModifiedValues_45662(array $originalModelData, object $nestedCompositionObject): array {
                            $modifiedValues = [];
                            $defaultValueMap = array (
);
    
                            foreach (array (
  'id' => 'getId',
  'note' => 'getNote',
) as $key => $accessor) {
                                if ((isset($originalModelData[$key]) || in_array($key, $defaultValueMap))
                                    && method_exists($nestedCompositionObject, $accessor)
                                    && ($modifiedValue = $nestedCompositionObject->$accessor()) !== ($originalModelData[$key] ?? !$modifiedValue)
                                ) {
                                    $modifiedValues[$key] = $modifiedValue;
                                }
                            }
    
                            return $modifiedValues;
                        }


}

// @codeCoverageIgnoreEnd
