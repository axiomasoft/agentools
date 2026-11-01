<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Tests\Confirmation;

require_once dirname(__DIR__,5).'/examples/platform/confirmed-write/bootstrap.php';

use AgenToolsPlatform\Core\Actions\Confirmation\Decision;
use AgenToolsPlatform\Core\Common\{Ref, TenantId};
use AgenToolsPlatform\Examples\ConfirmedWrite\{Composition, Host, KeyMaterial, Owner};
use AgenToolsPlatform\Postgres\Migrator;
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;

final class Fixture
{
    public IsolatedDatabase $database;
    public string $schema;
    public Composition $composition;
    public Owner $owner;
    public \PDO $replayPdo;
    public function __construct(?string $schema=null,?\AgenToolsPlatform\Core\Actions\ActionDefinition $definition=null)
    {
        $this->database=new IsolatedDatabase();
        $this->schema=$schema??'p3_'.bin2hex(random_bytes(12));
        if ($schema===null) {
            $this->database->createSchema($this->schema);
            (new Migrator($this->database->pdo,$this->schema))->migrate();
        }
        $host=new Host(new TenantId('tenant-a'));
        $this->replayPdo=(new IsolatedDatabase())->pdo;
        $this->composition=new Composition($this->database->pdo,$this->replayPdo,$this->schema,$host,
            new KeyMaterial(str_repeat('s',32),str_repeat('e',32)),$definition);
        $this->owner=new Owner($this->composition,(new IsolatedDatabase())->pdo,$this->schema);
        if ($schema===null) { $this->owner->provision(); }
    }
    public function approve(string $channel='ui'): \AgenToolsPlatform\Core\Actions\Confirmation\Preview
    {
        $submission=$this->composition->submission();
        $preview=$this->composition->confirmation->prepare($submission);
        $this->consent($preview,$channel);
        $this->composition->confirmation->approve($preview->token,$submission);
        return $preview;
    }
    public function consent(\AgenToolsPlatform\Core\Actions\Confirmation\Preview $preview,string $channel='ui'): void
    {
        $host=$this->composition->host;
        $source=new Ref($host->tenant,'host:consent:'.$preview->proposal->value);
        $host->consent=new Decision($source,$this->composition->hasher->hash($host->tenant,'host-consent',
            [$preview->binding->hash->value,$channel,$host->actor]),new Ref($host->tenant,$host->actor),
            $preview->binding->hash,$channel,$preview->binding->expiresAt);
    }
    /** @phpstan-impure Reads current SQL state; writes may have occurred since the preceding call. */
    public function count(string $table): int
    {
        return (int)$this->composition->environment->db->execute('SELECT count(*) FROM '.$this->composition->environment->db->table($table))->fetchColumn();
    }
    public function close(): void
    {
        if ($this->database->pdo->inTransaction()) { $this->database->pdo->rollBack(); }
        $this->database->dropSchema($this->schema);
    }
}
