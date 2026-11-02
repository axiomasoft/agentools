<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Knowledge\{EmbeddingSpace,Integrity,Release};
use AgenToolsPlatform\Knowledge\Retrieval\{Candidate,Query,ReleasePin,SearchIndex};
use PDO;

/** Synthetic host lexical adapter: real PG tsvector/GIN, constrained to pre-authorized exact releases. */
final readonly class PgIndex implements SearchIndex
{
    public function __construct(private PDO $pdo, private string $schema)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D',$schema)!==1 || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
            throw new \InvalidArgumentException('Safe PostgreSQL index required');
        }
    }
    public function space(): ?EmbeddingSpace { return null; }
    public function add(Release $release): void
    {
        foreach ($release->extraction->artifacts as $artifact) {
            if ($artifact->kind!=='fragment') { continue; }
            $stmt=$this->pdo->prepare('INSERT INTO "'.$this->schema.'".knowledge_demo_index VALUES (?,?,?,?,?,?,?,to_tsvector(\'simple\',?),?) ON CONFLICT DO NOTHING');
            $stmt->execute([$release->material->tenant,$release->corpus,$release->id,$release->hash(),$artifact->id,$artifact->start,$artifact->end,$artifact->text,Integrity::digest($artifact)]);
            $check=$this->pdo->prepare('SELECT release_hash,content_hash FROM "'.$this->schema.'".knowledge_demo_index WHERE tenant=? AND corpus=? AND release_id=? AND artifact=?');
            $check->execute([$release->material->tenant,$release->corpus,$release->id,$artifact->id]);$row=$check->fetch(PDO::FETCH_ASSOC);
            if ($row===false || $row['release_hash']!==$release->hash() || $row['content_hash']!==Integrity::digest($artifact)) {
                throw new \DomainException('Index identity conflict');
            }
        }
    }
    public function search(Query $query, array $releases, ?array $vector=null): array
    {
        $out=[];
        foreach ($releases as $release) {
            if ($release->material->tenant!==$query->scope->tenant) { throw new \DomainException('Foreign index partition'); }
            $pin=null;
            foreach ($query->releases as $candidate) {
                if ($candidate->material->tenant===$release->material->tenant && $candidate->corpus===$release->corpus
                    && $candidate->id===$release->id && $candidate->hash===$release->hash()) { $pin=$candidate;break; }
            }
            if ($pin===null) { throw new \DomainException('Unpinned index partition'); }
            $stmt=$this->pdo->prepare('SELECT artifact,start_byte,end_byte,ts_rank(terms,plainto_tsquery(\'simple\',?)) AS score
                FROM "'.$this->schema.'".knowledge_demo_index WHERE tenant=? AND corpus=? AND release_id=? AND release_hash=?
                AND terms @@ plainto_tsquery(\'simple\',?) ORDER BY score DESC,artifact LIMIT 32');
            $stmt->execute([$query->normalized,$query->scope->tenant,$release->corpus,$release->id,$release->hash(),$query->normalized]);
            while (($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false) {
                $out[]=new Candidate($pin,(string)$row['artifact'],(int)$row['start_byte'],(int)$row['end_byte'],(float)$row['score']);
            }
        }
        return $out;
    }
}
