<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Profiles\Skills;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, InvocationId, Ref, UtcInstant};
use AgenToolsPlatform\Core\Context\ContextBlock;
use AgenToolsPlatform\Core\Models\{ContentPart, JsonObject, MessageRole, ModelMessage, ModelRequest, ToolDefinition};
use AgenToolsPlatform\Core\Profiles\Skills\{PreparedSelection, SelectionRequest};
use AgenToolsPlatform\Tests\Profiles\Merge\Proof as Objects;

/** Scripted host adapter/transport capture; not a provider-support or live qualification claim. */
final class Capture
{
    public int $calls=0;
    public ?ModelRequest $received=null;
    /**
     * @var list<ContextBlock>
     */ public array $blocks=[];
    public function send(PreparedSelection $selection, SelectionRequest $request, Fixture $fixture): void
    {
        $data=Objects::object(json_decode(json_encode($selection,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR));
        if (!is_array($data['fragments']) || !is_array($data['tools'])) { throw new \RuntimeException('Projected lists required'); }
        $parts=[]; $tenant=$request->run->tenant;
        foreach ($data['fragments'] as $value) {
            $value=Objects::object($value);
            $source=Objects::object($value['source']);
            if (!is_string($value['text']) || !is_string($value['classification']) || $value['trust'] !== 'untrusted') { throw new \RuntimeException('Untrusted skill data required'); }
            if (!is_string($source['version'])) { throw new \RuntimeException('Pinned source version required'); }
            $identity=hash('sha256',json_encode($source,JSON_THROW_ON_ERROR));
            $block=new ContextBlock(new Ref($tenant,'skill-block:'.$identity), 'skill',new Ref($tenant,'skill-source:'.$identity),$source['version'],new Ref($tenant,'skill-owner'),
                'untrusted',$value['classification'],$request->purpose,new Ref($tenant,'skill-content:'.$identity),$value['text'],new UtcInstant('2030-01-01T00:00:00Z'),
                false,1,new CanonicalHasher($fixture->merge->json));
            $this->blocks[]=$block; $parts[]=ContentPart::text($block->modelView);
        }
        $tools=[];
        foreach ($data['tools'] as $value) {
            $value=Objects::object($value); $definition=Objects::object($value['definition']);
            if (!is_string($definition['id'])) { throw new \RuntimeException('Code tool ID required'); }
            $tools[]=new ToolDefinition($definition['id'],'Code registry tool',new JsonObject(Objects::object($value['input_schema'])));
        }
        $this->received=new ModelRequest(new InvocationId($tenant,'invocation-skills'),[new ModelMessage(MessageRole::User,$parts)],
            new Ref($tenant,'context-snapshot'),$request->contextHash,new Ref($tenant,'credential-ref'),new UtcInstant('2030-01-01T00:00:00Z'),
            system:'trusted system',policy:'current tenant guards',tools:$tools);
        ++$this->calls;
    }
}
