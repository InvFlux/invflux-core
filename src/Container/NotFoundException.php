<?php

declare(strict_types=1);

namespace Nandan108\InvFlux\Container;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
}
