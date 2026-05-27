<?php

namespace App\Service;

use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;

final class ContentFormatHelper
{
    public const WORKFLOW_STANDARD = Status::WORKFLOW_STANDARD;
    public const WORKFLOW_VIDEO = Status::WORKFLOW_VIDEO;

    public function isVideoFormat(?Format $format): bool
    {
        $name = mb_strtolower(trim((string) ($format?->getName() ?? '')));

        // Certains formats (ex: carrousel) utilisent le même pipeline "vidéo"
        // (KDrive, CM, actions de workflow).
        return in_array($name, ['vidéo', 'video', 'carrousel', 'carousel'], true);
    }

    public function isVideoContent(Content $content): bool
    {
        return $this->isVideoFormat($content->getFormat());
    }

    public function workflowForContent(Content $content): string
    {
        return $this->isVideoContent($content) ? self::WORKFLOW_VIDEO : self::WORKFLOW_STANDARD;
    }
}
