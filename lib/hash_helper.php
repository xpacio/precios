<?php

function flatHash(string $data): string
{
    return substr(strtoupper(hash('xxh3', $data)), -4);
}
