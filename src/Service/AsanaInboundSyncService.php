<?php

namespace App\Service;

use App\Entity\Content;

/**
 * @deprecated Utiliser AsanaBidirectionalSyncService — conservé pour compatibilité d'injection.
 */
final class AsanaInboundSyncService
{
    public function __construct(
        private readonly AsanaBidirectionalSyncService $bidirectionalSync,
    ) {
    }

    public function syncContent(Content $content, bool $flush = true): bool
    {
        return $this->bidirectionalSync->syncContent($content, $flush);
    }
}
