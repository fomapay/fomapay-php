# fomapay-php

SDK PHP officiel de **FomaPay** : encaissez en RDC par **FomaPay Direct** (QR / code marchand, dès le Niveau 0) et par **Mobile Money** (M-Pesa, Orange Money, Airtel Money, dès le Niveau 1).

- PHP 8.1+, aucune dépendance Composer (extensions `curl` et `json`)
- Fonctionne avec Laravel, Symfony, WordPress / WooCommerce ou du PHP sans framework
- Nouvelles tentatives automatiques et délai d'attente
- Vérification des webhooks (HMAC-SHA256)

> SDK **serveur** : il utilise votre clé secrète `sk_…`, qui ne doit jamais apparaître dans une page ou une application cliente.

## Intégration en 3 étapes

### 1. Installer

```bash
composer require fomapay/fomapay-php
```

### 2. Créer un paiement

```php
$fomapay = new \FomaPay\Client('sk_test_…'); // FomaPay Business › Développeurs

$payment = $fomapay->transactions()->create([
    'amount' => 1000,
    'currency' => 'CDF',
    'orderId' => 'CMD-2026-001',
    'customer' => ['name' => 'Jean Marc', 'phone' => '+243810000000', 'email' => 'jean@example.com'],
    'paymentModes' => ['fomapay_direct', 'mobile_money'],
    'callbackUrl' => 'https://marchand.cd/api/callback',
    'metadata' => ['custom_field' => 'valeur'],
]);

echo $payment->reference, ' ', $payment->qrCode; // à afficher au client (FomaPay Direct)

// ou débiter directement son compte Mobile Money (push USSD)
$fomapay->transactions()->payWithMobileMoney($payment->id, 'airtel_money', '+243970000000');
```

### 3. Recevoir la confirmation (webhook)

```php
$payload = file_get_contents('php://input'); // le corps BRUT, tel que reçu
$signature = $_SERVER['HTTP_X_FOMAPAY_SIGNATURE'] ?? null;

if (!\FomaPay\Webhook::verify($payload, $signature, 'whsec_…')) {
    http_response_code(400);
    exit;
}

$event = json_decode($payload, true);
if ($event['type'] === 'payment.succeeded') {
    livrer($event['data']['orderId']);
}
http_response_code(200);
```

`\FomaPay\Webhook::constructEvent($payload, $signature, $secret)` fait les deux d'un coup : il renvoie un `Event` typé (`$event->type`, `$event->data->orderId`) ou lève une `SignatureException`.

Avec Laravel : `$request->getContent()` et `$request->header('X-FomaPay-Signature')`.

## Référence

| Appel | Rôle |
| --- | --- |
| `$fomapay->payments()->create($params, $idempotencyKey)` | Crée une demande de paiement |
| `$fomapay->payments()->retrieve($idOuReference)` | Relit un paiement |
| `$fomapay->payments()->list(['limit' => 20, 'status' => 'success'])` | Derniers paiements de la boutique |
| `$fomapay->payments()->payWithMobileMoney($id, $operator, $phone)` | Push USSD vers le client |
| `$fomapay->payments()->simulate($id, 'success' \| 'failed')` | Force l'issue (sandbox uniquement) |
| `$fomapay->payouts()->create([...])` | Retrait vers Mobile Money |
| `$fomapay->balance()->retrieve()` | Solde par devise |
| `$fomapay->store()->retrieve()` | Boutique, niveau KYC et modes autorisés |

`transactions()` est un alias de `payments()`. Les montants sont en unités de la devise (`12.5` = 12,50 USD). Chaque modèle garde la réponse complète de l'API dans `->raw`.

## Erreurs

```php
use FomaPay\Exception\ApiException;
use FomaPay\Exception\NetworkException;

try {
    $fomapay->payments()->create(['amount' => 900, 'currency' => 'USD']);
} catch (ApiException $e) {       // l'API a répondu avec une erreur
    $e->getErrorCode();           // ex. ERROR_LEVEL_0_LIMIT_REACHED
    $e->getStatus();              // statut HTTP
    $e->getDetails();
} catch (NetworkException $e) {   // ERROR_NETWORK ou ERROR_TIMEOUT
}
```

Toutes héritent de `FomaPay\Exception\FomaPayException`.

## Fiabilité

```php
$fomapay = new \FomaPay\Client('sk_live_…', [
    'timeout' => 10,      // secondes, 30 par défaut
    'max_retries' => 3,   // 2 par défaut
]);
```

- **Nouvelles tentatives** avec attente exponentielle (0,5 s, 1 s… plafonnée à 8 s). Ne sont rejouées que les requêtes sans risque : les lectures, et les créations de paiement, qui portent toujours un en-tête `Idempotency-Key`. Un `429` est toujours rejoué en respectant `Retry-After`.
- **Idempotence** : passez le numéro de commande en second argument de `create()` pour qu'un appel répété par votre propre code renvoie le même paiement.
- **Retraits** : jamais rejoués automatiquement. Après une `NetworkException`, vérifiez l'état avant de relancer.
- **Transport** : l'option `http_client` accepte toute implémentation de `FomaPay\Http\HttpClientInterface` (Guzzle, Symfony HttpClient…).

## Sandbox

Avec une clé `sk_test_…`, aucun argent ne circule. Pour un push Mobile Money, le numéro décide de l'issue : terminé par `0001` → refusé, par `0002` → jamais validé, sinon confirmé après ~3 s.

## Tests

```bash
composer install
composer test
```

Tests d'intégration contre le sandbox (ignorés si la variable est absente) :

```bash
FOMAPAY_API_URL=http://localhost:8080 FOMAPAY_TEST_SECRET_KEY=sk_test_… composer test
```

Licence MIT.
