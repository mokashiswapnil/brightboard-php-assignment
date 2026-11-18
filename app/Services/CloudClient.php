<?php

namespace App\Services;

interface CloudClient
{
    /**
     * Fetch metadata from customer cloud storage provider.
     *
     * @param string $externalId
     * @param string $token
     * @return array{id: string, name: string}
     * @throws \App\Exceptions\CloudFileNotFoundException
     * @throws \App\Exceptions\CloudPermanentException
     * @throws \Throwable
     */
    public function fetchMetadata(string $externalId, string $token): array;

    /**
     * Acknowledge sync completion with cloud storage provider.
     *
     * @param string $externalId
     * @return void
     * @throws \Throwable
     */
    public function markSynced(string $externalId): void;
}
