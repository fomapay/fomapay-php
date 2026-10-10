<?php

declare(strict_types=1);

namespace FomaPay\Exception;

/** Aucune réponse : connexion impossible (ERROR_NETWORK) ou délai dépassé (ERROR_TIMEOUT). */
class NetworkException extends FomaPayException
{
}
