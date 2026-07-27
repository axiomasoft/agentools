<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p04_dynamic_ref;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp04dynamicrefschemajson
 * @package Bakeoff\p04_dynamic_ref 
 *
 * Экзотика 2020-12 (J6): расширяемый базовый список шагов через $dynamicRef/$dynamicAnchor. Ожидаем провал (crash/not-supported), нужен наблюдаемый факт, а не догадка.
 *
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp04dynamicrefschemajson implements JSONModelInterface
{
    

    
        /** @var string */
        protected $id;
    
        /** @var mixed[] */
        protected $children;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp04dynamicrefschemajson constructor.
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
            
        
            
                $this->processChildren($rawModelDataInput);
            
        

        
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
   'id',
   'children',
));

    

    return $additionalProperties;
})()) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\AdditionalPropertiesException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp04dynamicrefschemajson',
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
        
    
        
            /**
             * Get the value of children.
             *
             * 
             *
             * @return mixed[]
             */
            public function getChildren()
                : array
            {
                

                return $this->children;
            }

            
                /**
                 * Set the value of children.
                 *
                 * @param mixed[] $children
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setChildren(
                    array $children
                ): static {
                    if ($this->children === $children) {
                        return $this;
                    }

                    $value = $modelData['children'] = $children;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateChildren($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->children = $value;
                    $this->_rawModelDataInput['children'] = $children;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property children
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processChildren(array $modelData): void
            {
                

                $value = array_key_exists('children', $modelData) ? $modelData['children'] : $this->children;

                

                $this->children = $this->validateChildren($value, $modelData);
            }

            /**
             * Execute all validators for the property children
             */
            protected function validateChildren($value, array $modelData)
            {
                
                    

if (!array_key_exists('children', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'children',
)));
}

                
                    

if (!is_array($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'children',
  1 => 'array',
)));
}

                
                    $this->validateChildren_ArrayItem_6a66d74e30981($value, $modelData);
                

                return $value;
            }
        
    

    private function validateChildren_ArrayItem_6a66d74e30981(&$value, $modelData): void {
                    $invalidItems_7fcc7832b68957ae757e196fc51896ef = [];
                    
                    if (is_array($value) && (function (&$items) use (&$invalidItems_7fcc7832b68957ae757e196fc51896ef, $modelData) {
    
        $originalErrorRegistry = $this->_errorRegistry;
    

    foreach ($items as $index => &$value) {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        try {
            

            

            
                if ($this->_errorRegistry->getErrors()) {
                    $invalidItems_7fcc7832b68957ae757e196fc51896ef[$index] = $this->_errorRegistry->getErrors();
                }
            
        } catch (\Exception $e) {
            // collect all errors concerning invalid items
            isset($invalidItems_7fcc7832b68957ae757e196fc51896ef[$index])
                ? $invalidItems_7fcc7832b68957ae757e196fc51896ef[$index][] = $e
                : $invalidItems_7fcc7832b68957ae757e196fc51896ef[$index] = [$e];
        }
    }

    
        $this->_errorRegistry = $originalErrorRegistry;
    

    return !empty($invalidItems_7fcc7832b68957ae757e196fc51896ef);
})($value)) {
                        $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Arrays\InvalidItemException($value ?? null, ...array (
  0 => 'children',
  1 => $invalidItems_7fcc7832b68957ae757e196fc51896ef,
)));
                    }
                }


}

// @codeCoverageIgnoreEnd
