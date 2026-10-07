<?php

/**
 * Provider-agnostic contract for Module A2 — lets a different search API
 * (Bing, SerpApi, etc.) drop in later without touching WebsiteDiscovery.
 */
interface SearchProvider {
    /**
     * @return array<int, array{url: string, title: string, snippet: string}>
     */
    public function search(string $query, int $limit): array;
}
