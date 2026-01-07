<?php

declare(strict_types=1);

namespace CharityCashbook\Auth;

use CharityCashbook\Core\DatabaseInterface;
use CharityCashbook\Core\Encryption;
use CharityCashbook\Core\Logger;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\AbstractProvider;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Manages OAuth provider configuration and instances
 */
class OAuthProviderService
{
    private DatabaseInterface $db;
    private Encryption $encryption;
    private Logger $logger;
    private string $baseUrl;

    public function __construct(
        DatabaseInterface $db,
        Encryption $encryption,
        Logger $logger,
        string $baseUrl
    ) {
        $this->db = $db;
        $this->encryption = $encryption;
        $this->logger = $logger;
        // Normalize the base URL - remove trailing slash and fix double protocol if present
        $baseUrl = rtrim($baseUrl, '/');
        $baseUrl = preg_replace('#^(https?://)https?://#i', '$1', $baseUrl);
        $this->baseUrl = $baseUrl;
    }

    /**
     * Get all enabled OAuth providers
     */
    public function getEnabledProviders(): array
    {
        return $this->db->query(
            "SELECT id, provider_type, name FROM oauth_providers WHERE is_enabled = TRUE"
        );
    }

    /**
     * Get all OAuth providers
     */
    public function getAllProviders(): array
    {
        return $this->db->query(
            "SELECT id, provider_type, name, is_enabled, created_at FROM oauth_providers ORDER BY name"
        );
    }

    /**
     * Get a provider by ID
     */
    public function getProviderById(int $id): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM oauth_providers WHERE id = ?",
            [$id]
        );
    }

    /**
     * Get a provider instance for OAuth flow
     */
    public function getProviderInstance(int $providerId): AbstractProvider
    {
        $provider = $this->getProviderById($providerId);
        if (!$provider) {
            throw new \RuntimeException("Provider not found: $providerId");
        }

        $clientSecret = $this->encryption->decrypt($provider['client_secret_encrypted']);
        $redirectUri = $this->baseUrl . '/oauth/callback/' . $providerId;

        return match ($provider['provider_type']) {
            'google' => new Google([
                'clientId' => $provider['client_id'],
                'clientSecret' => $clientSecret,
                'redirectUri' => $redirectUri,
            ]),
            'microsoft' => new Azure([
                'clientId' => $provider['client_id'],
                'clientSecret' => $clientSecret,
                'redirectUri' => $redirectUri,
                'tenant' => !empty($provider['tenant_id']) ? $provider['tenant_id'] : 'common',
                'scopes' => ['openid', 'profile', 'email'],
            ]),
            'oidc' => $this->createOidcProvider($provider, $clientSecret, $redirectUri),
            default => throw new \RuntimeException("Unsupported provider type: {$provider['provider_type']}"),
        };
    }

    /**
     * Create a generic OIDC provider instance
     */
    private function createOidcProvider(array $provider, string $clientSecret, string $redirectUri): GenericProvider
    {
        // If discovery URL is provided, we could fetch endpoints (not implemented yet)
        // For now, require manual endpoint configuration
        
        if (empty($provider['authorization_endpoint']) || empty($provider['token_endpoint'])) {
            throw new \RuntimeException("OIDC provider requires authorization and token endpoints");
        }

        $scopes = $provider['scopes'] ? explode(' ', $provider['scopes']) : ['openid', 'email', 'profile'];

        return new GenericProvider([
            'clientId' => $provider['client_id'],
            'clientSecret' => $clientSecret,
            'redirectUri' => $redirectUri,
            'urlAuthorize' => $provider['authorization_endpoint'],
            'urlAccessToken' => $provider['token_endpoint'],
            'urlResourceOwnerDetails' => $provider['userinfo_endpoint'] ?? '',
            'scopes' => $scopes,
            'scopeSeparator' => ' ',
        ]);
    }

    /**
     * Create a new OAuth provider
     */
    public function createProvider(array $data): int
    {
        $encryptedSecret = $this->encryption->encrypt($data['client_secret']);

        $id = $this->db->insert('oauth_providers', [
            'provider_type' => $data['provider_type'],
            'name' => $data['name'],
            'client_id' => $data['client_id'],
            'client_secret_encrypted' => $encryptedSecret,
            'tenant_id' => $data['tenant_id'] ?? null,
            'discovery_url' => $data['discovery_url'] ?? null,
            'authorization_endpoint' => $data['authorization_endpoint'] ?? null,
            'token_endpoint' => $data['token_endpoint'] ?? null,
            'userinfo_endpoint' => $data['userinfo_endpoint'] ?? null,
            'scopes' => $data['scopes'] ?? null,
            'is_enabled' => $data['is_enabled'] ?? true,
        ]);

        $this->logger->info('OAuth provider created', [
            'provider_id' => $id,
            'name' => $data['name'],
            'type' => $data['provider_type'],
        ]);

        return $id;
    }

    /**
     * Update an OAuth provider
     */
    public function updateProvider(int $id, array $data): void
    {
        $updateData = [
            'name' => $data['name'],
            'client_id' => $data['client_id'],
        ];
        
        if (array_key_exists('tenant_id', $data)) {
            $updateData['tenant_id'] = $data['tenant_id'];
        }
        if (array_key_exists('discovery_url', $data)) {
            $updateData['discovery_url'] = $data['discovery_url'];
        }
        if (array_key_exists('authorization_endpoint', $data)) {
            $updateData['authorization_endpoint'] = $data['authorization_endpoint'];
        }
        if (array_key_exists('token_endpoint', $data)) {
            $updateData['token_endpoint'] = $data['token_endpoint'];
        }
        if (array_key_exists('userinfo_endpoint', $data)) {
            $updateData['userinfo_endpoint'] = $data['userinfo_endpoint'];
        }
        if (array_key_exists('scopes', $data)) {
            $updateData['scopes'] = $data['scopes'];
        }
        if (isset($data['is_enabled'])) {
            $updateData['is_enabled'] = $data['is_enabled'];
        }

        if (!empty($data['client_secret'])) {
            $updateData['client_secret_encrypted'] = $this->encryption->encrypt($data['client_secret']);
        }

        $this->db->update('oauth_providers', $updateData, 'id = ?', [$id]);

        $this->logger->info('OAuth provider updated', ['provider_id' => $id]);
    }

    /**
     * Toggle provider enabled status
     */
    public function toggleProvider(int $id): bool
    {
        $provider = $this->getProviderById($id);
        if (!$provider) {
            return false;
        }

        $newStatus = !$provider['is_enabled'];
        $this->db->execute(
            "UPDATE oauth_providers SET is_enabled = ? WHERE id = ?",
            [$newStatus, $id]
        );

        $this->logger->info('OAuth provider toggled', [
            'provider_id' => $id,
            'is_enabled' => $newStatus,
        ]);

        return $newStatus;
    }

    /**
     * Get providers allowed for a charity
     */
    public function getProvidersForCharity(int $charityId): array
    {
        return $this->db->query(
            "SELECT op.* 
             FROM oauth_providers op
             JOIN charity_oauth_providers cop ON cop.oauth_provider_id = op.id
             WHERE cop.charity_id = ? AND op.is_enabled = TRUE",
            [$charityId]
        );
    }

    /**
     * Generate OAuth state token
     */
    public function generateState(): string
    {
        $state = bin2hex(random_bytes(32));
        
        // Start PHP session if not started
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $_SESSION['oauth_state'] = $state;
        
        return $state;
    }

    /**
     * Verify OAuth state token
     */
    public function verifyState(string $state): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        $storedState = $_SESSION['oauth_state'] ?? '';
        unset($_SESSION['oauth_state']);
        
        return !empty($storedState) && hash_equals($storedState, $state);
    }
}
