<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\RenderResult;
use App\Domain\Video\VideoRendererInterface;
use App\Models\Video;

final class FakeVideoRenderer implements VideoRendererInterface
{
    private ?RenderResult $result = null;

    public function respondWith(RenderResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function render(Video $video): RenderResult
    {
        return $this->result ?? new RenderResult(
            path: "projects/{$video->content_project_id}/renders/{$video->id}.mp4",
            duration: (float) $video->scenes->sum('duration'),
            width: 1080,
            height: 1920,
        );
    }
}
