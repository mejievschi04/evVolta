<?php

namespace App\Services;

use App\Models\Station;
use Illuminate\Support\Collection;

class QrStationResolver
{
    private const MIN_PARTIAL_MATCH_LENGTH = 10;

    /**
     * @return array{station: Station, connector_id: int}|null
     */
    public function resolve(string $raw, ?Collection $stations = null): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $stations ??= Station::query()->get();
        $connectorId = $this->extractConnectorId($raw);
        $candidates = $this->extractCandidates($raw);

        foreach ($candidates as $candidate) {
            $match = $stations->first(fn (Station $station) => $this->stationMatchesToken($station, $candidate));

            if ($match) {
                return [
                    'station' => $match,
                    'connector_id' => $connectorId,
                ];
            }
        }

        foreach ($candidates as $candidate) {
            $match = $this->resolvePartialMatch($stations, $candidate);

            if ($match) {
                return [
                    'station' => $match,
                    'connector_id' => $connectorId,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function extractCandidates(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $candidates = [$raw];

        if (str_starts_with(strtolower($raw), 'station:')) {
            $candidates[] = substr($raw, strlen('station:'));
        }

        if (preg_match_all('/[0-9A-F]{10,24}/i', $raw, $hexMatches)) {
            foreach ($hexMatches[0] as $hexToken) {
                $candidates[] = $hexToken;
                $candidates[] = strtoupper($hexToken);
            }
        }

        foreach (preg_split('/[,|;#\\s]+/', $raw) ?: [] as $segment) {
            $segment = trim((string) $segment);
            if ($segment !== '') {
                $candidates[] = $segment;
            }
        }

        if (str_contains($raw, '://') || str_contains($raw, '?')) {
            $parts = parse_url($raw) ?: [];
            $query = [];
            parse_str($parts['query'] ?? '', $query);

            foreach ([
                'station_id',
                'stationId',
                'id',
                'identity',
                'serial',
                'ocpp_identity',
                'sn',
                'cpId',
                'chargePointId',
                'deviceId',
                'chargerId',
                'chargePointSerialNumber',
                'cid',
                'pileCode',
                'pileNo',
                'gunNo',
                'gunCode',
                'deviceCode',
                'cp_id',
            ] as $key) {
                if (! empty($query[$key])) {
                    $candidates[] = (string) $query[$key];
                }
            }

            foreach (explode('/', trim($parts['path'] ?? '', '/')) as $segment) {
                if ($segment !== '') {
                    $candidates[] = urldecode($segment);
                }
            }
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ([
                'station_id',
                'stationId',
                'id',
                'identity',
                'serial',
                'ocpp_identity',
                'sn',
                'chargePointSerialNumber',
                'cpId',
                'connectorId',
                'connector_id',
                'cid',
                'pileCode',
                'pileNo',
                'gunNo',
                'gunCode',
                'deviceCode',
            ] as $key) {
                if (! empty($decoded[$key])) {
                    $candidates[] = (string) $decoded[$key];
                }
            }
        }

        $expanded = [];
        foreach ($candidates as $candidate) {
            $expanded[] = $candidate;
            $expanded = array_merge($expanded, $this->stripConnectorSuffixVariants($candidate));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            $expanded
        ))));
    }

    public function stationMatchesToken(Station $station, string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        foreach ($station->scanTokens() as $scanToken) {
            if ($this->tokensEqual($token, $scanToken)) {
                return true;
            }
        }

        return false;
    }

    public function extractConnectorId(string $raw): int
    {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            foreach (['connectorId', 'connector_id', 'gunNo', 'gun_no', 'port'] as $key) {
                if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
                    return max(1, (int) $decoded[$key]);
                }
            }

            if (! empty($decoded['connector']) && is_string($decoded['connector'])) {
                return $this->connectorLabelToId($decoded['connector']);
            }
        }

        if (preg_match('/[?&](?:connector(?:Id|_id)?|gunNo|port)=([0-9]+)/i', $raw, $matches)) {
            return max(1, (int) $matches[1]);
        }

        $compact = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
        if (preg_match('/^([A-Z0-9]{8,})([AB])$/', $compact, $matches)) {
            return $this->connectorLabelToId($matches[2]);
        }

        if (preg_match('/[:\/\-_,|;]([AB]|[12])$/i', trim($raw), $matches)) {
            return $this->connectorLabelToId($matches[1]);
        }

        if (preg_match('/[,|;]([12])[,|;]?$/', trim($raw), $matches)) {
            return max(1, (int) $matches[1]);
        }

        return 1;
    }

    /**
     * @return list<string>
     */
    private function stripConnectorSuffixVariants(string $value): array
    {
        $variants = [];
        $trimmed = trim($value);

        if (preg_match('/^(.+?)[:\/\-_,|;]([AB]|[12])$/i', $trimmed, $matches)) {
            $variants[] = $matches[1];
        }

        $compact = strtoupper(preg_replace('/[^A-Z0-9]/', '', $trimmed) ?? '');
        if (preg_match('/^([A-Z0-9]{8,})([AB])$/', $compact, $matches)) {
            $variants[] = $matches[1];
            $variants[] = strtolower($matches[1]);
        }

        return $variants;
    }

    private function connectorLabelToId(string $label): int
    {
        $normalized = strtoupper(trim($label));

        return match ($normalized) {
            'B', '2' => 2,
            default => 1,
        };
    }

    private function resolvePartialMatch(Collection $stations, string $candidate): ?Station
    {
        $candidateAlnum = strtolower(preg_replace('/[^a-z0-9]/', '', $candidate) ?? '');
        if (strlen($candidateAlnum) < self::MIN_PARTIAL_MATCH_LENGTH) {
            return null;
        }

        $matches = $stations->filter(function (Station $station) use ($candidateAlnum) {
            foreach ($station->scanTokens() as $scanToken) {
                $tokenAlnum = strtolower(preg_replace('/[^a-z0-9]/', '', $scanToken) ?? '');
                if ($tokenAlnum === '') {
                    continue;
                }

                if (
                    str_contains($candidateAlnum, $tokenAlnum)
                    || str_contains($tokenAlnum, $candidateAlnum)
                ) {
                    return true;
                }
            }

            return false;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function tokensEqual(string $left, string $right): bool
    {
        if (strcasecmp($left, $right) === 0) {
            return true;
        }

        $leftAlnum = strtolower(preg_replace('/[^a-z0-9]/', '', $left) ?? '');
        $rightAlnum = strtolower(preg_replace('/[^a-z0-9]/', '', $right) ?? '');

        return $leftAlnum !== '' && $leftAlnum === $rightAlnum;
    }
}
