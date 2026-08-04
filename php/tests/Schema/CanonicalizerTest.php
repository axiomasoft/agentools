<?php

declare(strict_types=1);

namespace AgenTools\Tests\Schema;

use AgenTools\Schema\CanonicalizationException;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Schema\RawDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CanonicalizerTest extends TestCase
{
    /** AOB-32; official RFC 8785 vector: cyberphone/json-canonicalization arrays.json. */
    public function testItMatchesTheOfficialArraysVector(): void
    {
        $input = "[\n  56,\n  {\n    \"d\": true,\n    \"10\": null,\n    \"1\": [ ]\n  }\n]";

        self::assertSame('[56,{"1":[],"10":null,"d":true}]', (new Canonicalizer())->canonicalizeJson($input));
    }

    /** AOB-32, AOB-33; official RFC 8785 vector: cyberphone/json-canonicalization structures.json. */
    public function testItMatchesTheOfficialStructuresVector(): void
    {
        $input = '{"1":{"f":{"f":"hi","F":5},"\\n":56.0},"10":{},"":"empty","a":{},"111":[{"e":"yes","E":"no"}],"A":{}}';

        self::assertSame('{"":"empty","1":{"\\n":56,"f":{"F":5,"f":"hi"}},"10":{},"111":[{"E":"no","e":"yes"}],"A":{},"a":{}}', (new Canonicalizer())->canonicalizeJson($input));
    }

    /** AOB-32, AOB-35; official RFC 8785 vector: cyberphone/json-canonicalization values.json. */
    public function testItMatchesTheOfficialValuesVector(): void
    {
        $input = <<<'JSON'
{
  "numbers": [333333333.33333329, 1E30, 4.50, 2e-3, 0.000000000000000000000000001],
  "string": "\u20ac$\u000F\u000aA'\u0042\u0022\u005c\\\"\/",
  "literals": [null, true, false]
}
JSON;

        $expected = <<<'JSON'
{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\u000f\nA'B\"\\\\\"/"}
JSON;

        self::assertSame($expected, (new Canonicalizer())->canonicalizeJson($input));
    }

    /** AOB-32, AOB-33, AOB-34; RFC 8785 object ordering vector. */
    public function testItCanonicalizesObjectKeysInJcsOrder(): void
    {
        $result = (new Canonicalizer())->canonicalize(['€' => 'Euro Sign', '1' => 'One', '😀' => 'Grinning Face']);

        self::assertSame('{"1":"One","€":"Euro Sign","😀":"Grinning Face"}', $result);
    }

    /** AOB-36 */
    #[DataProvider('nonFiniteNumbers')]
    public function testItRejectsNonFiniteNumbers(float $value): void
    {
        $this->expectException(CanonicalizationException::class);
        (new Canonicalizer())->canonicalize(['value' => $value]);
    }

    /** AOB-37 */
    public function testItRejectsDuplicateObjectNamesBeforeDecoding(): void
    {
        $this->expectException(CanonicalizationException::class);
        $this->expectExceptionMessage('duplicate member names');

        (new Canonicalizer())->canonicalizeJson('{"key":1,"key":2}');
    }

    /** @return array<string, array{float}> */
    public static function nonFiniteNumbers(): array
    {
        return ['nan' => [NAN], 'positive infinity' => [INF], 'negative infinity' => [-INF]];
    }

    /** IDE-41 */
    public function testRawDocumentRoundTripsAnUnknownMember(): void
    {
        $raw = ['known' => 'value', 'future_extension' => ['nested' => true]];

        self::assertSame($raw, (new RawDocument($raw))->toArray());
    }

    /** IDE-41, AOB-42 */
    public function testRawJsonRoundTripsUnknownMembersByteForByte(): void
    {
        $json = "{\n  \"known\": \"value\",\n  \"future_extension\": {\"nested\": true}\n}";

        self::assertSame($json, RawDocument::fromJson($json)->toJson());
    }
}
