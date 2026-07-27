<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p01_open_enum;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp01openenumschemajson
 * @package Bakeoff\p01_open_enum 
 *
 * Приём открытых перечислений AgenTools (E2): oneOf из const-веток известных значений плюс fallback string с шаблоном расширения.
 *
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp01openenumschemajson implements JSONModelInterface
{
    

    
        /** @var string Известные значения плюс fallback-ветка вида '<product>.<ext>'. */
        protected $kind;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp01openenumschemajson constructor.
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
));

    

    return $additionalProperties;
})()) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\AdditionalPropertiesException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp01openenumschemajson',
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
             * Get the value of kind.
             *
             * Известные значения плюс fallback-ветка вида '<product>.<ext>'.
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
                 * @param string $kind Известные значения плюс fallback-ветка вида '<product>.<ext>'.
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

                
                    $this->validateKind_ComposedProperty_6a66d74e21ba3($value, $modelData);
                

                return $value;
            }
        
    

    private function validateKind_ComposedProperty_6a66d74e21ba3(&$value, $modelData): void {
                    
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
    $succeededCompositionElements = 4;
    $validatorComponentIndex = 0;
    $originalModelData = $value;
    $proposedValue = null;
    $modifiedValues = [];

    

    
        $originalErrorRegistry = $this->_errorRegistry;
    

    
        try {
            

                
                    // collect errors for each composition element
                    $this->_errorRegistry = new ErrorRegistryException();
                

                

                

                
                    

if ($value !== 'llm') {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidConstException($value ?? null, ...array (
  0 => 'kind',
  1 => 'llm',
)));
}

                

                
                    $compositionErrorCollection[] = $this->_errorRegistry;

                    

                    // an error inside the composed validation occurred. Throw an exception to count the validity of the
                    // composition item
                    if ($this->_errorRegistry->getErrors()) {
                        throw new \Exception();
                    }
                

                
                    $proposedValue = $proposedValue ?? $value;
                

                if (is_object($value)) {
                    $modifiedValues = array_merge($modifiedValues, $this->_getModifiedValues_7f31a($originalModelData, $value));
                }
            
        } catch (\Exception $e) {
            

            

            $succeededCompositionElements--;
        }

        $value = $originalModelData;
        $validatorComponentIndex++;
    
        try {
            

                
                    // collect errors for each composition element
                    $this->_errorRegistry = new ErrorRegistryException();
                

                

                

                
                    

if ($value !== 'tool') {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidConstException($value ?? null, ...array (
  0 => 'kind',
  1 => 'tool',
)));
}

                

                
                    $compositionErrorCollection[] = $this->_errorRegistry;

                    

                    // an error inside the composed validation occurred. Throw an exception to count the validity of the
                    // composition item
                    if ($this->_errorRegistry->getErrors()) {
                        throw new \Exception();
                    }
                

                
                    $proposedValue = $proposedValue ?? $value;
                

                if (is_object($value)) {
                    $modifiedValues = array_merge($modifiedValues, $this->_getModifiedValues_7f31a($originalModelData, $value));
                }
            
        } catch (\Exception $e) {
            

            

            $succeededCompositionElements--;
        }

        $value = $originalModelData;
        $validatorComponentIndex++;
    
        try {
            

                
                    // collect errors for each composition element
                    $this->_errorRegistry = new ErrorRegistryException();
                

                

                

                
                    

if ($value !== 'elicitation') {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidConstException($value ?? null, ...array (
  0 => 'kind',
  1 => 'elicitation',
)));
}

                

                
                    $compositionErrorCollection[] = $this->_errorRegistry;

                    

                    // an error inside the composed validation occurred. Throw an exception to count the validity of the
                    // composition item
                    if ($this->_errorRegistry->getErrors()) {
                        throw new \Exception();
                    }
                

                
                    $proposedValue = $proposedValue ?? $value;
                

                if (is_object($value)) {
                    $modifiedValues = array_merge($modifiedValues, $this->_getModifiedValues_7f31a($originalModelData, $value));
                }
            
        } catch (\Exception $e) {
            

            

            $succeededCompositionElements--;
        }

        $value = $originalModelData;
        $validatorComponentIndex++;
    
        try {
            

                
                    // collect errors for each composition element
                    $this->_errorRegistry = new ErrorRegistryException();
                

                

                

                
                    

if (!is_string($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'kind',
  1 => 'string',
)));
}

                
                    

if (is_string($value) && !preg_match(base64_decode('L15bYS16XVthLXowLTldKlwuW2EtejAtOV9dKyQv'), $value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\String\PatternException($value ?? null, ...array (
  0 => 'kind',
  1 => '^[a-z][a-z0-9]*\\.[a-z0-9_]+$',
)));
}

                

                
                    $compositionErrorCollection[] = $this->_errorRegistry;

                    

                    // an error inside the composed validation occurred. Throw an exception to count the validity of the
                    // composition item
                    if ($this->_errorRegistry->getErrors()) {
                        throw new \Exception();
                    }
                

                
                    $proposedValue = $proposedValue ?? $value;
                

                if (is_object($value)) {
                    $modifiedValues = array_merge($modifiedValues, $this->_getModifiedValues_7f31a($originalModelData, $value));
                }
            
        } catch (\Exception $e) {
            

            

            $succeededCompositionElements--;
        }

        $value = $originalModelData;
        $validatorComponentIndex++;
    

    
        $value = $proposedValue;
    

    
        $this->_errorRegistry = $originalErrorRegistry;
    

    $result = !($succeededCompositionElements === 1);

    

    return $result;
})($value)
) {
                        $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\ComposedValue\OneOfException($value ?? null, ...array (
  0 => 'kind',
  1 => $succeededCompositionElements,
  2 => $compositionErrorCollection,
)));
                    }
                }


                        private function _getModifiedValues_7f31a(array $originalModelData, object $nestedCompositionObject): array {
                            $modifiedValues = [];
                            $defaultValueMap = array (
);
    
                            foreach (array (
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
