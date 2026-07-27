<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p06_if_then_else;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp06ifthenelseschemajson
 * @package Bakeoff\p06_if_then_else 
 *
 * Условные поля профиля инструмента: тип шага диктует обязательные поля (образец — Step.allOf в scenario.json), плюс dependentSchemas для approval, зависимого от side_effect.
 *
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp06ifthenelseschemajson implements JSONModelInterface
{
    

    
        /** @var string */
        protected $kind;
    
        /** @var string|null */
        protected $promptRef;
    
        /** @var string|null */
        protected $tool;
    
        /** @var string|null */
        protected $sideEffect;
    
        /** @var string|null */
        protected $approval;
    
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
     * Httpsagentoolsdevbakeoffp06ifthenelseschemajson constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        
            $this->executeBaseValidators($rawModelDataInput);
        

        
            
                $this->processKind($rawModelDataInput);
            
        
            
                $this->processPromptRef($rawModelDataInput);
            
        
            
                $this->processTool($rawModelDataInput);
            
        
            
                $this->processSideEffect($rawModelDataInput);
            
        
            
                $this->processApproval($rawModelDataInput);
            
        
            
        

        
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
   'kind',
   'prompt_ref',
   'tool',
   'side_effect',
   'approval',
));

    

    return $additionalProperties;
})()) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\AdditionalPropertiesException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson',
  1 => $additionalProperties,
)));
}

            

            
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
             * Get the value of kind.
             *
             * 
             *
             * @return string
             */
            public function getKind()
                : string
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
                    

                    $this->validateComposition_0($modelData);



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
                

                $value = array_key_exists('kind', $modelData) ? $modelData['kind'] : $this->kind;

                

                $this->kind = $this->validateKind($value, $modelData);
            }

            /**
             * Execute all validators for the property kind
             */
            protected function validateKind($value, array $modelData)
            {
                
                    

if (!array_key_exists('kind', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'kind',
)));
}

                
                    

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'kind',
  1 => 'string',
)));
}

                
                    

if (!in_array($value, array (
   'llm',
   'tool',
), true)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\EnumException($value ?? null, ...array (
  0 => 'kind',
  1 => 
  array (
    0 => 'llm',
    1 => 'tool',
  ),
)));
}

                

                return $value;
            }
        
    
        
            /**
             * Get the value of prompt_ref.
             *
             * 
             *
             * @return string|null
             */
            public function getPromptRef()
                : ?string
            {
                

                return $this->promptRef;
            }

            
                /**
                 * Set the value of prompt_ref.
                 *
                 * @param string $promptRef
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setPromptRef(
                    string $promptRef
                ): static {
                    if ($this->promptRef === $promptRef) {
                        return $this;
                    }

                    $value = $modelData['prompt_ref'] = $promptRef;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    $this->validateComposition_0($modelData);



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
                
                    if (!array_key_exists('prompt_ref', $modelData) && $this->promptRef === null) {
                        return;
                    }
                

                $value = array_key_exists('prompt_ref', $modelData) ? $modelData['prompt_ref'] : $this->promptRef;

                

                $this->promptRef = $this->validatePromptRef($value, $modelData);
            }

            /**
             * Execute all validators for the property promptRef
             */
            protected function validatePromptRef($value, array $modelData)
            {
                
                    

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'prompt_ref',
  1 => 'string',
)));
}

                

                return $value;
            }
        
    
        
            /**
             * Get the value of tool.
             *
             * 
             *
             * @return string|null
             */
            public function getTool()
                : ?string
            {
                

                return $this->tool;
            }

            
                /**
                 * Set the value of tool.
                 *
                 * @param string $tool
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setTool(
                    string $tool
                ): static {
                    if ($this->tool === $tool) {
                        return $this;
                    }

                    $value = $modelData['tool'] = $tool;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    $this->validateComposition_0($modelData);



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
                
                    if (!array_key_exists('tool', $modelData) && $this->tool === null) {
                        return;
                    }
                

                $value = array_key_exists('tool', $modelData) ? $modelData['tool'] : $this->tool;

                

                $this->tool = $this->validateTool($value, $modelData);
            }

            /**
             * Execute all validators for the property tool
             */
            protected function validateTool($value, array $modelData)
            {
                
                    

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'tool',
  1 => 'string',
)));
}

                

                return $value;
            }
        
    
        
            /**
             * Get the value of side_effect.
             *
             * 
             *
             * @return string|null
             */
            public function getSideEffect()
                : ?string
            {
                

                return $this->sideEffect;
            }

            
                /**
                 * Set the value of side_effect.
                 *
                 * @param string $sideEffect
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setSideEffect(
                    string $sideEffect
                ): static {
                    if ($this->sideEffect === $sideEffect) {
                        return $this;
                    }

                    $value = $modelData['side_effect'] = $sideEffect;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    



                    $value = $this->validateSideEffect($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->sideEffect = $value;
                    $this->_rawModelDataInput['side_effect'] = $sideEffect;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property sideEffect
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processSideEffect(array $modelData): void
            {
                
                    if (!array_key_exists('side_effect', $modelData) && $this->sideEffect === null) {
                        return;
                    }
                

                $value = array_key_exists('side_effect', $modelData) ? $modelData['side_effect'] : $this->sideEffect;

                

                $this->sideEffect = $this->validateSideEffect($value, $modelData);
            }

            /**
             * Execute all validators for the property sideEffect
             */
            protected function validateSideEffect($value, array $modelData)
            {
                
                    

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'side_effect',
  1 => 'string',
)));
}

                
                    

if (!in_array($value, array (
   'read',
   'write',
), true)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\EnumException($value ?? null, ...array (
  0 => 'side_effect',
  1 => 
  array (
    0 => 'read',
    1 => 'write',
  ),
)));
}

                

                return $value;
            }
        
    
        
            /**
             * Get the value of approval.
             *
             * 
             *
             * @return string|null
             */
            public function getApproval()
                : ?string
            {
                

                return $this->approval;
            }

            
                /**
                 * Set the value of approval.
                 *
                 * @param string $approval
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setApproval(
                    string $approval
                ): static {
                    if ($this->approval === $approval) {
                        return $this;
                    }

                    $value = $modelData['approval'] = $approval;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    



                    $value = $this->validateApproval($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->approval = $value;
                    $this->_rawModelDataInput['approval'] = $approval;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property approval
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processApproval(array $modelData): void
            {
                
                    if (!array_key_exists('approval', $modelData) && $this->approval === null) {
                        return;
                    }
                

                $value = array_key_exists('approval', $modelData) ? $modelData['approval'] : $this->approval;

                

                $this->approval = $this->validateApproval($value, $modelData);
            }

            /**
             * Execute all validators for the property approval
             */
            protected function validateApproval($value, array $modelData)
            {
                
                    

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'approval',
  1 => 'string',
)));
}

                
                    

if (!in_array($value, array (
   'none',
   'confirm',
), true)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\EnumException($value ?? null, ...array (
  0 => 'approval',
  1 => 
  array (
    0 => 'none',
    1 => 'confirm',
  ),
)));
}

                

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

    $ifException = $thenException = $elseException = null;
    if (
(function (&$value) use (
    &$modelData,
    &$ifException,
    &$thenException,
    &$elseException
) {
    $originalModelData = $value;
    
        $originalErrorRegistry = $this->_errorRegistry;
        $this->_errorRegistry = new ErrorRegistryException();
    

    try {
        $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a5c3($value) : $value;
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
  0 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson',
  1 => 'object',
)));
}

        
            

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a5c3)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson',
  1 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a5c3',
)));
}

        

        
            if ($this->_errorRegistry->getErrors()) {
                throw $this->_errorRegistry;
            }
        
    } catch (\Exception $e) {
        $ifException = $e;
    }
    $value = $originalModelData;

    
        $this->_errorRegistry = new ErrorRegistryException();
    

    if (!$ifException) {
        
            try {
                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a7d4($value) : $value;
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
  0 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a7d4)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson',
  1 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a7d4',
)));
}

                

                
                    if ($this->_errorRegistry->getErrors()) {
                        throw $this->_errorRegistry;
                    }
                
            } catch (\Exception $e) {
                $thenException = $e;
            }
        
    } else {
        
            try {
                $value = (function ($value) {
    try {
        return is_array($value) ? new Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a81d($value) : $value;
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
  0 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson',
  1 => 'object',
)));
}

                
                    

if (is_object($value) && !($value instanceof \Exception) && !($value instanceof Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a81d)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\InvalidInstanceOfException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson',
  1 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson_Httpsagentoolsdevbakeoffp06ifthenelseschemajson6a66d74e3a81d',
)));
}

                

                
                    if ($this->_errorRegistry->getErrors()) {
                        throw $this->_errorRegistry;
                    }
                
            } catch (\Exception $e) {
                $elseException = $e;
            }
        
    }

    
        $this->_errorRegistry = $originalErrorRegistry;
    

    return $thenException || $elseException;
})($value)
) {
        $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\ComposedValue\ConditionalException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp06ifthenelseschemajson',
  1 => $ifException,
  2 => $thenException,
  3 => $elseException,
)));
    }

    foreach (array_keys($modifiedModelData) as $property) {
        $modifiedModelData[$property] = $modelData[$property];
    }
}



}

// @codeCoverageIgnoreEnd
