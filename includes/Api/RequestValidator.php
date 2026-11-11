<?php
/**
 * Request Validator
 *
 * Validates incoming inputs:
 * - Allows internal hostnames, localhost, private IP addresses and custom ports.
 * - Does NOT block SSRF/internal network destinations (core internal audit requirement).
 * - Sanitizes pagination and sorting parameters against allowlists.
 */

namespace LoremChecker\Api;

class RequestValidator
{
    /**
     * Validate website URL for auditing.
     * Allows http/https on public domains, internal hostnames, localhost, and private IPs.
     */
    public static function validateWebsiteUrl(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            throw new \InvalidArgumentException('Website URL is required.');
        }

        $url = trim($url);
        $parts = parse_url($url);

        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('Invalid URL format. Scheme and host are required.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only HTTP and HTTPS URLs are supported.');
        }

        return $url;
    }

    public static function validateId(mixed $val, string $paramName = 'id'): int
    {
        if ($val === null || !is_numeric($val) || (int)$val <= 0) {
            throw new \InvalidArgumentException("Valid numeric {$paramName} is required.");
        }
        return (int)$val;
    }

    public static function sanitizePagination(array $query, int $defaultPageSize = 50, int $maxPageSize = 200): array
    {
        $page = isset($query['page']) && is_numeric($query['page']) ? max(1, (int)$query['page']) : 1;
        $pageSize = isset($query['limit']) && is_numeric($query['limit']) ? min($maxPageSize, max(1, (int)$query['limit'])) : $defaultPageSize;

        return ['page' => $page, 'limit' => $pageSize];
    }

    public static function sanitizeSortField(?string $field, array $allowedFields, string $default): string
    {
        if ($field !== null && in_array($field, $allowedFields, true)) {
            return $field;
        }
        return $default;
    }
}
