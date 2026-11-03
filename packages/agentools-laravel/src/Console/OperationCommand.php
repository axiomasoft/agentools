<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Console;

use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Laravel\Operations\{Facade,RequestSource};
use Illuminate\Console\Command;

abstract class OperationCommand extends Command
{
    protected string $verb;
    public function handle(Facade $facade,RequestSource $source): int
    {
        $handle=$this->argument('operation_ref'); $limit=$this->option('limit'); $after=$this->option('after');
        if (!is_string($handle) || !is_string($limit) || !ctype_digit($limit)
            || (int)$limit<1 || (int)$limit>100 || ($after!==null&&!is_string($after))) { return self::INVALID; }
        try {
            $apply=$this->option('apply')===true;
            $command=$source->command($handle,$this->verb,$apply,(int)$limit,$after);
            if ($command->verb!==$this->verb || $command->apply!==$apply || $command->limit!==(int)$limit || $command->after!==$after) {
                throw new \LogicException('Trusted command binding conflict');
            }
            $result=$facade->execute($command);
            $this->line(json_encode($result,JSON_THROW_ON_ERROR));
            return in_array($result['status']??null,['not_ready','unknown'],true)?self::FAILURE:self::SUCCESS;
        } catch (ContractViolation $error) {
            $this->line(json_encode(['status'=>'not_ready','code'=>$error->errorCode->value],JSON_THROW_ON_ERROR));
            return self::FAILURE;
        } catch (\Throwable) {
            $this->line('{"status":"unknown","code":"operation_unresolved"}');
            return self::FAILURE;
        }
    }
}
