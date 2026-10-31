<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Anthropic;

final class SseDecoder
{
    private string $buffer = '';
    /** @return iterable<\stdClass> */
    public function feed(string $bytes): iterable
    {
        $this->buffer .= $bytes;
        while (preg_match('/(?:\r\n|\n){2}/', $this->buffer, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1];
            if ($offset > 1048576) { throw new ProtocolFailure('sse_frame_limit'); }
            $frame = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + strlen($match[0][0]));
            $type = null; $data = [];
            foreach (explode("\n", str_replace("\r\n", "\n", $frame)) as $line) {
                if (str_starts_with($line, 'event:')) { $type = ltrim(substr($line, 6), ' '); }
                elseif (str_starts_with($line, 'data:')) { $data[] = str_starts_with(substr($line, 5), ' ') ? substr($line, 6) : substr($line, 5); }
            }
            if ($data === []) { continue; }
            try { $event = json_decode(implode("\n", $data), false, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new ProtocolFailure('invalid_sse_json'); }
            if (!$event instanceof \stdClass || ($event->type ?? null) !== $type) { throw new ProtocolFailure('sse_type_mismatch'); }
            yield $event;
        }
        if (strlen($this->buffer) > 1048576) { throw new ProtocolFailure('sse_frame_limit'); }
    }
    public function finish(): void
    {
        if (trim($this->buffer) !== '') { throw new ProtocolFailure('incomplete_sse_frame'); }
    }
}