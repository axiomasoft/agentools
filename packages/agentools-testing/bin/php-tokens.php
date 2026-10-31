<?php

declare(strict_types=1);

// Parse without executing candidate source. Used by the architecture checker.
$source = stream_get_contents(STDIN);
$tokens = token_get_all($source, TOKEN_PARSE);
$result = [];
foreach ($tokens as $token) {
    if (is_array($token)) {
        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
            continue;
        }
        $result[] = [token_name($token[0]), $token[1], $token[2]];
    } else {
        $result[] = [$token, $token, 0];
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR);
