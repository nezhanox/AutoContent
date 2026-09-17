<?php

namespace App\Domain\Video;

use App\Models\Video;

interface VideoRendererInterface
{
    public function render(Video $video): RenderResult;
}
