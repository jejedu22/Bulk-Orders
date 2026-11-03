<?php

namespace App\Service;

use App\Entity\Commande;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Paiement en ligne par HelloAsso (API v5, « Checkout »).
 *
 * Le compte (identifiants API et organisation) est saisi dans l'administration
 * (Paramètres → HelloAsso) et stocké dans la table settings. Tant qu'il n'est
 * pas renseigné, le paiement en ligne n'est pas proposé.
 *
 * Le statut d'un paiement est toujours relu auprès de l'API HelloAsso : ni les
 * paramètres de l'URL de retour ni le contenu d'une notification ne suffisent
 * à confirmer une commande.
 */
class HelloAsso
{
    public const CLIENT_ID = 'helloasso_client_id';
    public const CLIENT_SECRET = 'helloasso_client_secret';
    public const ORGANIZATION = 'helloasso_organization';
    public const SANDBOX = 'helloasso_sandbox';

    private const API_URL = 'https://api.helloasso.com';
    private const SANDBOX_API_URL = 'https://api.helloasso-sandbox.com';

    private $settings;
    private $httpClient;
    private $logger;

    /** @var array<string, string> jetons d'accès déjà obtenus, par compte */
    private $tokens = [];

    public function __construct(OptionsSettings $settings, HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->settings = $settings;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId() && '' !== $this->clientSecret() && '' !== $this->organization();
    }

    public function clientId(): string
    {
        return trim($this->settings->get(self::CLIENT_ID));
    }

    public function clientSecret(): string
    {
        return trim($this->settings->get(self::CLIENT_SECRET));
    }

    /**
     * Identifiant de l'organisation dans les URL HelloAsso (« organizationSlug »).
     */
    public function organization(): string
    {
        return trim($this->settings->get(self::ORGANIZATION));
    }

    /**
     * Environnement de test HelloAsso (helloasso-sandbox.com).
     */
    public function isSandbox(): bool
    {
        return '1' === trim($this->settings->get(self::SANDBOX));
    }

    /**
     * Montant à payer en centimes (prix définitifs, voir canPay()).
     */
    public function amount(Commande $commande): int
    {
        return (int) round($commande->getTotal() * 100);
    }

    /**
     * Le paiement en ligne est proposé pour une commande non encore payée,
     * une fois les prix définitifs de tous ses produits connus, et si son
     * montant est d'au moins 1 €.
     */
    public function canPay(Commande $commande): bool
    {
        return $this->isConfigured() && !$commande->getPayee() && $commande->hasFinalPrices() && $this->amount($commande) >= 100;
    }

    /**
     * Crée un paiement HelloAsso pour la commande et retourne l'URL de la page
     * de paiement. L'identifiant du paiement est enregistré sur la commande
     * (à enregistrer en base par l'appelant).
     *
     * @throws HelloAssoException
     */
    public function createCheckout(Commande $commande, string $returnUrl, string $errorUrl, string $backUrl): string
    {
        $amount = $this->amount($commande);
        $user = $commande->getUser();
        $itemName = sprintf('Commande n°%d', $commande->getId());
        if (null !== $commande->getJourDistrib() && null !== $commande->getJourDistrib()->getDate()) {
            $itemName .= ' - vente du ' . $commande->getJourDistrib()->getDate()->format('d/m/Y');
        }

        $payer = array_filter([
            'firstName' => $user ? $this->payerName((string) $user->getPrenom()) : null,
            'lastName' => $user ? $this->payerName((string) $user->getNom()) : null,
            'email' => $user ? trim((string) $user->getMail()) : null,
        ]);

        $data = $this->api('POST', '/checkout-intents', [
            'json' => array_filter([
                'totalAmount' => $amount,
                'initialAmount' => $amount,
                'itemName' => mb_substr($itemName, 0, 250),
                'backUrl' => $backUrl,
                'errorUrl' => $errorUrl,
                'returnUrl' => $returnUrl,
                'containsDonation' => false,
                'payer' => $payer ?: null,
                'metadata' => ['commande' => $commande->getId()],
            ], fn ($value) => null !== $value),
        ]);

        if (empty($data['id']) || empty($data['redirectUrl'])) {
            throw new HelloAssoException('Réponse inattendue de HelloAsso à la création du paiement.');
        }

        $commande->setHelloassoCheckoutId((int) $data['id']);

        return (string) $data['redirectUrl'];
    }

    /**
     * Interroge HelloAsso sur le paiement de la commande. Retourne l'identifiant
     * de la commande HelloAsso si le paiement est autorisé, null sinon.
     *
     * @throws HelloAssoException
     */
    public function paidOrderId(Commande $commande): ?int
    {
        if (null === $commande->getHelloassoCheckoutId()) {
            return null;
        }

        $data = $this->api('GET', '/checkout-intents/' . $commande->getHelloassoCheckoutId());

        // Le paiement doit bien concerner cette commande
        if ((int) ($data['metadata']['commande'] ?? 0) !== $commande->getId()) {
            return null;
        }

        // « order » n'est présent qu'une fois le paiement effectué
        $order = $data['order'] ?? null;
        if (!\is_array($order) || empty($order['id'])) {
            return null;
        }
        foreach ($order['payments'] ?? [] as $payment) {
            if ('Authorized' === ($payment['state'] ?? '')) {
                return (int) $order['id'];
            }
        }

        return null;
    }

    /**
     * Vérifie les identifiants auprès de HelloAsso et l'accès à l'organisation.
     *
     * @return array<array{0: string, 1: string}> liste de [niveau (success|warning|danger), message]
     */
    public function check(string $clientId, string $clientSecret, string $organization, bool $sandbox): array
    {
        if ('' === $clientId || '' === $clientSecret || '' === $organization) {
            return [['danger', 'Renseignez l\'identifiant client, la clé secrète et l\'organisation.']];
        }

        try {
            $token = $this->token($clientId, $clientSecret, $sandbox);
        } catch (HelloAssoException $e) {
            return [['danger', $e->getMessage()]];
        }
        $results = [['success', 'Connexion à HelloAsso réussie : les identifiants sont valides.']];

        try {
            $response = $this->httpClient->request('GET', $this->baseUrl($sandbox) . '/v5/organizations/' . rawurlencode($organization), [
                'auth_bearer' => $token,
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            if (200 === $status) {
                $name = $response->toArray(false)['name'] ?? $organization;
                $results[] = ['success', sprintf('Organisation trouvée : %s.', $name)];
            } elseif (401 === $status || 403 === $status) {
                $results[] = ['danger', sprintf('Ces identifiants ne donnent pas accès à l\'organisation « %s ».', $organization)];
            } elseif (404 === $status) {
                $results[] = ['danger', sprintf('Organisation « %s » introuvable : vérifiez son identifiant dans l\'adresse de sa page HelloAsso.', $organization)];
            } else {
                $results[] = ['danger', sprintf('Réponse inattendue de HelloAsso (code %d).', $status)];
            }
        } catch (HttpExceptionInterface $e) {
            $results[] = ['danger', 'HelloAsso est injoignable : ' . $e->getMessage()];
        }

        return $results;
    }

    /**
     * Appel de l'API de l'organisation configurée.
     *
     * @throws HelloAssoException
     */
    private function api(string $method, string $path, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new HelloAssoException('Le paiement HelloAsso n\'est pas configuré.');
        }

        $url = $this->baseUrl($this->isSandbox()) . '/v5/organizations/' . rawurlencode($this->organization()) . $path;
        try {
            $response = $this->httpClient->request($method, $url, $options + [
                'auth_bearer' => $this->token($this->clientId(), $this->clientSecret(), $this->isSandbox()),
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->error('HelloAsso : {method} {url} a répondu {status} : {body}', [
                    'method' => $method, 'url' => $url, 'status' => $status, 'body' => $response->getContent(false),
                ]);
                throw new HelloAssoException(sprintf('HelloAsso a refusé la demande (code %d).', $status));
            }

            return $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HelloAsso injoignable : {message}', ['message' => $e->getMessage()]);
            throw new HelloAssoException('HelloAsso est injoignable : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Jeton d'accès OAuth2 (« client credentials »).
     *
     * @throws HelloAssoException
     */
    private function token(string $clientId, string $clientSecret, bool $sandbox): string
    {
        $key = ($sandbox ? 'sandbox:' : '') . $clientId . ':' . hash('sha256', $clientSecret);
        if (isset($this->tokens[$key])) {
            return $this->tokens[$key];
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl($sandbox) . '/oauth2/token', [
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            if (400 === $status || 401 === $status || 403 === $status) {
                throw new HelloAssoException('HelloAsso refuse ces identifiants : vérifiez l\'identifiant client et la clé secrète' . ($sandbox ? ' (environnement de test).' : '.'));
            }
            if (200 !== $status) {
                throw new HelloAssoException(sprintf('Réponse inattendue de HelloAsso (code %d).', $status));
            }
            $token = $response->toArray(false)['access_token'] ?? '';
        } catch (HttpExceptionInterface $e) {
            throw new HelloAssoException('HelloAsso est injoignable : ' . $e->getMessage(), 0, $e);
        }

        if ('' === $token) {
            throw new HelloAssoException('Réponse inattendue de HelloAsso : aucun jeton d\'accès.');
        }

        return $this->tokens[$key] = $token;
    }

    private function baseUrl(bool $sandbox): string
    {
        return $sandbox ? self::SANDBOX_API_URL : self::API_URL;
    }

    /**
     * HelloAsso refuse les noms de payeur contenant des chiffres ou certains
     * caractères spéciaux : on ne transmet que les lettres, espaces, tirets et
     * apostrophes (le payeur peut corriger sur la page de paiement).
     */
    private function payerName(string $name): ?string
    {
        $name = trim(preg_replace("/[^\\p{L} '\\-]+/u", '', $name));

        return mb_strlen($name) >= 2 ? $name : null;
    }
}
