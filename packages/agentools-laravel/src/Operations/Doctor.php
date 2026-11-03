<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

use AgenToolsPlatform\Postgres\Runtime\{Connection,RepositoryContext};
use Composer\InstalledVersions;

final readonly class Doctor
{
    public const HOST_CHECKS=['bindings','registry','production_adapters','secrets','capability_evidence','queue','scanner','outbox_lag','retention'];
    public function __construct(private RepositoryContext $environment,private Host $host,private bool $production) {}
    /** @return array<string,mixed> */
    public function inspect(Command $c): array
    {
        $this->environment->authorize($c->context); $this->host->authority()->authorize($c);
        $findings=[new Finding('php',version_compare(PHP_VERSION,'8.3.0','>=')?'ready':'not_ready','installed_version',PHP_VERSION)];
        foreach (['pdo_pgsql','sodium','json'] as $extension) {
            $findings[]=new Finding($extension,extension_loaded($extension)?'ready':'not_ready',extension_loaded($extension)?'loaded':'missing_extension');
        }
        foreach (['agentools/sdk','agentools/platform-core','agentools/platform-postgres','laravel/framework'] as $name) {
            $version=InstalledVersions::isInstalled($name)?InstalledVersions::getPrettyVersion($name):null;
            $findings[]=new Finding(str_replace('/','.',$name),$version===null?'not_ready':'ready',
                $version===null?'missing_dependency':'installed_exact_version',$version);
        }
        try {
            $db=$this->environment->db;
            $row=$db->row('SELECT current_setting(\'transaction_isolation\') AS isolation,version() AS version');
            $findings[]=new Finding('postgres',$row===null?'unknown':'ready',$row===null?'unavailable':'connected');
            $ok=true;
            $files=glob(dirname(__DIR__,3).'/agentools-postgres/migrations/*.sql')?:[];
            $history=$db->execute('SELECT version,checksum FROM '.$db->table('platform_migrations').' ORDER BY version')->fetchAll(\PDO::FETCH_KEY_PAIR);
            foreach ($files as $file) { if (($history[basename($file)]??null)!==hash_file('sha256',$file)) { $ok=false; } }
            $ok=$ok && $files!==[] && count($history)===count($files);
            foreach (['ops_facade_intents','ops_facade_receipts'] as $name) {
                $exists=$db->row('SELECT to_regclass(:table_name) IS NOT NULL AS present',['table_name'=>$db->table($name)]);
                $ok=$ok && ($exists['present']??false)===true;
            }
            $findings[]=new Finding('migrations',$ok?'ready':'not_ready',$ok?'checksums_match':'migration_missing_or_drift');
            // Isolation is an actual deployment policy claim supplied by the host, never inferred from a connection.
        } catch (\Throwable) { $findings[]=new Finding('migrations','unknown','dependency_unavailable'); }
        $checks=[];
        try {
            foreach ($this->host->diagnose($c) as $finding) {
                if (!in_array($finding->check,[...self::HOST_CHECKS,'pg_isolation'],true) || isset($checks[$finding->check])) {
                    throw new \LogicException('Invalid diagnostic proof');
                }
                $checks[$finding->check]=$finding;
            }
        } catch (\Throwable) { $checks=[]; }
        foreach ([...self::HOST_CHECKS,'pg_isolation'] as $check) { $findings[]=$checks[$check]??new Finding($check,'unknown','missing_probe'); }
        if ($this->production) {
            try { \AgenToolsPlatform\Laravel\Managed\HostBindings::assertProductionService($this->host); }
            catch (\Throwable) { $findings[]=new Finding('production_guard','not_ready','production_fake_or_unverified'); }
        }
        $status='ready';
        foreach ($findings as $f) { if ($f->status!=='ready') { $status='not_ready'; } }
        return ['status'=>$status,'dry_run'=>true,'findings'=>array_map(static fn(Finding $f): array=>$f->metadata(),$findings),
            'readiness_claim'=>'technical-metadata-only'];
    }
}
