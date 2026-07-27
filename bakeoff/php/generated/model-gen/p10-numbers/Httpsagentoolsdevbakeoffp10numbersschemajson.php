<?php

// @codingStandardsIgnoreFile
// @codeCoverageIgnoreStart

declare(strict_types = 1);


    namespace Bakeoff\p10_numbers;



    use PHPModelGenerator\Interfaces\JSONModelInterface;

    use PHPModelGenerator\Exception\ErrorRegistryException;


/**
 * Class Httpsagentoolsdevbakeoffp10numbersschemajson
 * @package Bakeoff\p10_numbers 
 *
 * Сериализация чисел ES6 (JCS-смежное): integer в минимальных единицах (копейки/центы), число за пределами Number.MAX_SAFE_INTEGER и экспоненциальная запись 1e21 — проверяем, сохраняет ли генератор точность и форму числа при round-trip.
 *
 * This is an auto-implemented class implemented by the php-json-schema-model-generator.
 * If you need to implement something in this class use inheritance. Else you will lose your changes if the classes
 * are re-generated.
 */
class Httpsagentoolsdevbakeoffp10numbersschemajson implements JSONModelInterface
{
    

    
        /** @var int Сумма в минорных единицах (копейки), integer без плавающей точки. */
        protected $amountMinor;
    
        /** @var int Число за пределами Number.MAX_SAFE_INTEGER (2^53-1). */
        protected $bigInteger;
    
        /** @var float Число в экспоненциальной записи (1e21 — за пределами обычного диапазона integer в JS). */
        protected $scientific;
    
    /** @var array */
    protected $_rawModelDataInput = [];

    
        /** @var ErrorRegistryException Collect all validation errors */
        protected $_errorRegistry;
    

    /**
     * Httpsagentoolsdevbakeoffp10numbersschemajson constructor.
     *
     * @param array $rawModelDataInput
     *
     * @throws ErrorRegistryException
     */
    public function __construct(array $rawModelDataInput = [])
    {
        
            $this->_errorRegistry = new ErrorRegistryException();
        

        

        
            $this->executeBaseValidators($rawModelDataInput);
        

        
            
                $this->processAmountMinor($rawModelDataInput);
            
        
            
                $this->processBigInteger($rawModelDataInput);
            
        
            
                $this->processScientific($rawModelDataInput);
            
        

        
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
   'amount_minor',
   'big_integer',
   'scientific',
));

    

    return $additionalProperties;
})()) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\AdditionalPropertiesException($value ?? null, ...array (
  0 => 'Httpsagentoolsdevbakeoffp10numbersschemajson',
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
             * Get the value of amount_minor.
             *
             * Сумма в минорных единицах (копейки), integer без плавающей точки.
             *
             * @return int
             */
            public function getAmountMinor()
                : int
            {
                

                return $this->amountMinor;
            }

            
                /**
                 * Set the value of amount_minor.
                 *
                 * @param int $amountMinor Сумма в минорных единицах (копейки), integer без плавающей точки.
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setAmountMinor(
                    int $amountMinor
                ): static {
                    if ($this->amountMinor === $amountMinor) {
                        return $this;
                    }

                    $value = $modelData['amount_minor'] = $amountMinor;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateAmountMinor($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->amountMinor = $value;
                    $this->_rawModelDataInput['amount_minor'] = $amountMinor;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property amountMinor
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processAmountMinor(array $modelData): void
            {
                

                $value = array_key_exists('amount_minor', $modelData) ? $modelData['amount_minor'] : $this->amountMinor;

                

                $this->amountMinor = $this->validateAmountMinor($value, $modelData);
            }

            /**
             * Execute all validators for the property amountMinor
             */
            protected function validateAmountMinor($value, array $modelData)
            {
                
                    

if (!array_key_exists('amount_minor', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'amount_minor',
)));
}

                
                    

if (!is_int($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'amount_minor',
  1 => 'int',
)));
}

                

                return $value;
            }
        
    
        
            /**
             * Get the value of big_integer.
             *
             * Число за пределами Number.MAX_SAFE_INTEGER (2^53-1).
             *
             * @return int
             */
            public function getBigInteger()
                : int
            {
                

                return $this->bigInteger;
            }

            
                /**
                 * Set the value of big_integer.
                 *
                 * @param int $bigInteger Число за пределами Number.MAX_SAFE_INTEGER (2^53-1).
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setBigInteger(
                    int $bigInteger
                ): static {
                    if ($this->bigInteger === $bigInteger) {
                        return $this;
                    }

                    $value = $modelData['big_integer'] = $bigInteger;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateBigInteger($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->bigInteger = $value;
                    $this->_rawModelDataInput['big_integer'] = $bigInteger;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property bigInteger
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processBigInteger(array $modelData): void
            {
                

                $value = array_key_exists('big_integer', $modelData) ? $modelData['big_integer'] : $this->bigInteger;

                

                $this->bigInteger = $this->validateBigInteger($value, $modelData);
            }

            /**
             * Execute all validators for the property bigInteger
             */
            protected function validateBigInteger($value, array $modelData)
            {
                
                    

if (!array_key_exists('big_integer', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'big_integer',
)));
}

                
                    

if (!is_int($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'big_integer',
  1 => 'int',
)));
}

                

                return $value;
            }
        
    
        
            /**
             * Get the value of scientific.
             *
             * Число в экспоненциальной записи (1e21 — за пределами обычного диапазона integer в JS).
             *
             * @return float
             */
            public function getScientific()
                : float
            {
                

                return $this->scientific;
            }

            
                /**
                 * Set the value of scientific.
                 *
                 * @param float $scientific Число в экспоненциальной записи (1e21 — за пределами обычного диапазона integer в JS).
                 *
                 * @throws ErrorRegistryException
                 *
                 * @return static
                 */
                public function setScientific(
                    float $scientific
                ): static {
                    if ($this->scientific === $scientific) {
                        return $this;
                    }

                    $value = $modelData['scientific'] = $scientific;

                    
                        $this->_errorRegistry = new ErrorRegistryException();
                    

                    

                    $value = $this->validateScientific($value, $modelData);

                    
                        if ($this->_errorRegistry->getErrors()) {
                            throw $this->_errorRegistry;
                        }
                    

                    $this->scientific = $value;
                    $this->_rawModelDataInput['scientific'] = $scientific;

                    

                    return $this;
                }
            

            /**
             * Extract the value, perform validations and set the property scientific
             *
             * @param array $modelData
             *
             * @throws ErrorRegistryException
             */
            protected function processScientific(array $modelData): void
            {
                

                $value = array_key_exists('scientific', $modelData) ? $modelData['scientific'] : $this->scientific;

                $value = is_int($value) ? (float) $value : $value;

                $this->scientific = $this->validateScientific($value, $modelData);
            }

            /**
             * Execute all validators for the property scientific
             */
            protected function validateScientific($value, array $modelData)
            {
                
                    

if (!array_key_exists('scientific', $modelData)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Object\RequiredValueException($value ?? null, ...array (
  0 => 'scientific',
)));
}

                
                    

if (!is_float($value)) {
    $this->_errorRegistry->addError(new \PHPModelGenerator\Exception\Generic\InvalidTypeException($value ?? null, ...array (
  0 => 'scientific',
  1 => 'float',
)));
}

                

                return $value;
            }
        
    

    
}

// @codeCoverageIgnoreEnd
