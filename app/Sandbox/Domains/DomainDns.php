<?php

namespace App\Sandbox\Domains;

/**
 * DNS lookups for custom domains (DOM-001) and organizations' email domains (ORG-008). Its own class so tests can stand in for the internet.
 */
class DomainDns
{
    /**
     * The IPv4 and IPv6 addresses a name resolves to, following CNAMEs.
     *
     * @return list<string>
     */
    public function addresses(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }

    /**
     * The values of a name's TXT records.
     *
     * @return list<string>
     */
    public function txt(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_TXT) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record): ?string => isset($record['entries']) ? implode('', $record['entries']) : ($record['txt'] ?? null),
            $records,
        )));
    }

    /**
     * Whether a name is a root domain (example.com, example.co.uk), which can't have a CNAME at most DNS hosts.
     * A short list of second-level suffixes stands in for the public suffix list.
     */
    public static function isApex(string $hostname): bool
    {
        $labels = explode('.', $hostname);

        return count($labels) === 2
            || (count($labels) === 3 && strlen($labels[2]) === 2 && in_array($labels[1], ['co', 'com', 'net', 'org', 'gov', 'ac', 'edu', 'ne', 'or'], true));
    }
}
