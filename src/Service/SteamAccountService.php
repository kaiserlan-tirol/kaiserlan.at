<?php

namespace App\Service;

use App\Exception\SteamApiException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SteamAccountService
{
    private const PROFILE_URL_REGEX = '~^(?:https?://)?(?:www\.)?steamcommunity\.com/(?:id|profiles)/([^/?#]+)~i';
    private const CANDIDATE_REGEX = '/^[A-Za-z0-9_.-]{2,64}$/';
    private const STEAMID64_REGEX = '/^7656\d{13}$/';
    private const ACCOUNTID_REGEX = '/^\d{1,10}$/';
    private const STEAMID64_BASE = 76561197960265728;

    public function __construct(
        private readonly HttpClientInterface $steamClient,
        private readonly LoggerInterface $logger,
        private readonly string $steamApiKey
    ) {
    }

    /**
     * Splits a free text steam account profile entry into the account names it
     * may contain. Profile urls are reduced to their vanity name or SteamID64.
     *
     * @return list<array{account: string, fromUrl: bool}> fromUrl marks an unambiguous steamcommunity url
     */
    public static function candidates(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $candidates = [];
        $seen = [];
        foreach (preg_split('/\s+/', $value) as $token) {
            $fromUrl = (bool) preg_match(self::PROFILE_URL_REGEX, $token, $matches);
            if ($fromUrl) {
                $token = $matches[1];
            }
            $token = trim($token, '/');
            if (preg_match(self::CANDIDATE_REGEX, $token) && !in_array($token, $seen, true)) {
                $seen[] = $token;
                $candidates[] = ['account' => $token, 'fromUrl' => $fromUrl];
            }
        }

        return $candidates;
    }

    public static function isSteamId64(string $value): bool
    {
        return (bool) preg_match(self::STEAMID64_REGEX, $value);
    }

    public function isConfigured(): bool
    {
        return $this->steamApiKey !== '';
    }

    /**
     * Resolves a single account name to a SteamID64, or null if unresolvable.
     */
    public function resolveSteamId64(string $candidate): ?string
    {
        if (self::isSteamId64($candidate)) {
            return $candidate;
        }

        $resolved = $this->resolveVanityUrl($candidate);
        if ($resolved !== null) {
            return $resolved;
        }

        if (preg_match(self::ACCOUNTID_REGEX, $candidate)) {
            return (string) (self::STEAMID64_BASE + (int) $candidate);
        }

        return null;
    }

    private function resolveVanityUrl(string $vanity): ?string
    {
        $response = $this->get('ISteamUser/ResolveVanityURL/v1/', ['vanityurl' => $vanity]);

        return ($response['response']['success'] ?? null) === 1
            ? $response['response']['steamid']
            : null;
    }

    /**
     * @param string[] $steamIds
     * @return array<string, array{personaname: string, profileurl: string}> keyed by SteamID64
     */
    public function fetchPlayerSummaries(array $steamIds): array
    {
        $players = [];
        foreach (array_chunk(array_values(array_unique($steamIds)), 100) as $chunk) {
            $response = $this->get('ISteamUser/GetPlayerSummaries/v2/', ['steamids' => implode(',', $chunk)]);
            foreach ($response['response']['players'] ?? [] as $player) {
                $players[$player['steamid']] = [
                    'personaname' => $player['personaname'] ?? '',
                    'profileurl' => $player['profileurl'] ?? '',
                ];
            }
        }

        return $players;
    }

    private function get(string $path, array $query): array
    {
        if ($this->steamApiKey === '') {
            throw new SteamApiException('STEAM_API_KEY is not configured.');
        }

        try {
            return $this->steamClient->request('GET', '/'.$path, [
                'query' => $query + ['key' => $this->steamApiKey],
            ])->toArray();
        } catch (TransportExceptionInterface $e) {
            // The API was never reached (dns, tls, timeout). Treating this as
            // "not resolvable" would silently mislabel every single account.
            throw new SteamApiException('Steam API nicht erreichbar: '.$this->redact($e->getMessage()));
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Steam API request failed', ['path' => $path, 'error' => $this->redact($e->getMessage())]);

            return [];
        }
    }

    private function redact(string $message): string
    {
        return str_replace($this->steamApiKey, '***', $message);
    }
}
