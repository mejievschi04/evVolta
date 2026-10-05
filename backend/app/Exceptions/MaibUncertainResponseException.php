<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * MAIB a raspuns cu eroare de server (5xx) la o cerere care modifica starea (ex. retur).
 * Cererea poate sa fi fost totusi procesata in spatele gateway-ului lor, deci rezultatul
 * e necunoscut: nu se trateaza ca esec sigur (nu se restituie automat soldul).
 */
class MaibUncertainResponseException extends RuntimeException
{
}
