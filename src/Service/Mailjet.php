<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Bridge\Mailjet\Transport\MailjetApiTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Configuration Mailjet saisie dans l'administration (Paramètres → Mailjet) :
 * clés API et expéditeur des newsletters, stockés dans la table settings.
 */
class Mailjet
{
    public const API_KEY = 'mailjet_api_key';
    public const SECRET_KEY = 'mailjet_secret_key';
    public const SENDER = 'mailjet_sender';

    private const API_URL = 'https://api.mailjet.com/v3/REST/';

    private $settings;
    private $httpClient;
    private $dispatcher;
    private $logger;

    public function __construct(OptionsSettings $settings, HttpClientInterface $httpClient, EventDispatcherInterface $dispatcher, LoggerInterface $logger)
    {
        $this->settings = $settings;
        $this->httpClient = $httpClient;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey() && '' !== $this->secretKey();
    }

    public function apiKey(): string
    {
        return trim($this->settings->get(self::API_KEY));
    }

    public function secretKey(): string
    {
        return trim($this->settings->get(self::SECRET_KEY));
    }

    /**
     * Expéditeur des newsletters ; vide = expéditeur habituel de l'application.
     */
    public function sender(): string
    {
        return trim($this->settings->get(self::SENDER));
    }

    /**
     * Transport d'envoi par l'API Mailjet. Le dispatcher d'événements permet
     * au profiler et aux tests de voir les e-mails comme ceux du mailer.
     */
    public function transport(): TransportInterface
    {
        return new MailjetApiTransport($this->apiKey(), $this->secretKey(), $this->httpClient, $this->dispatcher, $this->logger);
    }

    /**
     * Vérifie les clés auprès de Mailjet et l'état de l'expéditeur.
     *
     * @return array<array{0: string, 1: string}> liste de [niveau (success|warning|danger), message]
     */
    public function check(string $apiKey, string $secretKey, string $sender): array
    {
        if ('' === $apiKey || '' === $secretKey) {
            return [['danger', 'Renseignez la clé API et la clé secrète.']];
        }

        try {
            $response = $this->httpClient->request('GET', self::API_URL . 'sender', [
                'auth_basic' => [$apiKey, $secretKey],
                'query' => ['Limit' => 1000],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                return [['danger', 'Mailjet refuse ces clés : vérifiez la clé API et la clé secrète.']];
            }
            if (200 !== $status) {
                return [['danger', sprintf('Réponse inattendue de Mailjet (code %d).', $status)]];
            }
            $senders = $response->toArray()['Data'] ?? [];
        } catch (HttpExceptionInterface $e) {
            return [['danger', 'Mailjet est injoignable : ' . $e->getMessage()]];
        }

        $results = [['success', 'Connexion à Mailjet réussie : les clés sont valides.']];
        if ('' === $sender) {
            $results[] = ['warning', 'Aucun expéditeur renseigné : l\'expéditeur habituel de l\'application sera utilisé, il doit être validé dans Mailjet.'];

            return $results;
        }

        $results[] = $this->senderStatus($sender, $senders);

        return $results;
    }

    /**
     * L'expéditeur est accepté si son adresse, ou son domaine (« *@domaine »),
     * est validé (statut « Active ») dans Mailjet.
     */
    private function senderStatus(string $sender, array $senders): array
    {
        $sender = strtolower($sender);
        $domain = '*@' . substr(strrchr($sender, '@') ?: '', 1);
        $found = null;
        foreach ($senders as $item) {
            $email = strtolower($item['Email'] ?? '');
            if ($email === $sender || $email === $domain) {
                $found = $item;
                if ('Active' === ($item['Status'] ?? '')) {
                    return ['success', sprintf('L\'expéditeur %s est validé dans Mailjet.', $sender)];
                }
            }
        }

        if (null !== $found) {
            return ['warning', sprintf('L\'expéditeur %s n\'est pas encore validé dans Mailjet (statut : %s). Ouvrez l\'e-mail de confirmation envoyé par Mailjet.', $sender, $found['Status'] ?? 'inconnu')];
        }

        return ['warning', sprintf('L\'expéditeur %s est inconnu de Mailjet : ajoutez-le dans Mailjet → Paramètres du compte → Adresses et domaines d\'expéditeur.', $sender)];
    }
}
